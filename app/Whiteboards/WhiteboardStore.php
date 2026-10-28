<?php

namespace App\Whiteboards;

use App\Models\Artifact;
use App\Models\User;
use App\Models\WhiteboardElement;
use Illuminate\Support\Facades\DB;

/**
 * Grava e lê os elementos de um quadro. Cada gravação sobe o relógio do quadro
 * (artifacts.whiteboard_seq) e marca os elementos gravados com ele; o ao vivo
 * devolve só o que tem seq maior que o último que o navegador já viu.
 *
 * Quem vence quando duas pessoas mudam o mesmo elemento é a regra do Excalidraw:
 * a version maior; empate, o versionNonce menor.
 */
class WhiteboardStore
{
    public function __construct(private ElementSanitizer $sanitizer) {}

    /**
     * Elementos vindos do navegador (já com version/versionNonce do Excalidraw).
     *
     * @param  list<array<string, mixed>>  $elements
     * @return array{seq: int, saved: list<string>, stale: list<array<string, mixed>>}
     */
    public function save(Artifact $board, ?User $user, array $elements): array
    {
        $clean = array_map(fn ($element) => $this->sanitizer->clean((array) $element), $elements);

        return $this->write($board, $user, $clean, force: false);
    }

    /**
     * Elementos gerados no servidor (IA, templates, restaurar versão): sempre
     * vencem a versão guardada, com version + 1 e um versionNonce novo.
     *
     * @param  list<array<string, mixed>>  $elements
     * @return array{seq: int, saved: list<string>, stale: list<array<string, mixed>>}
     */
    public function put(Artifact $board, ?User $user, array $elements): array
    {
        $clean = array_map(fn ($element) => $this->sanitizer->clean(['version' => 1, 'versionNonce' => 0] + (array) $element), $elements);

        return $this->write($board, $user, $clean, force: true);
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return array{seq: int, saved: list<string>, stale: list<array<string, mixed>>}
     */
    private function write(Artifact $board, ?User $user, array $elements, bool $force): array
    {
        if (! $board->isWhiteboard()) {
            throw new InvalidWhiteboard('Este artifact não é um whiteboard.');
        }

        return DB::transaction(function () use ($board, $user, $elements, $force) {
            // Uma gravação por vez em cada quadro: o seq nunca repete.
            $locked = Artifact::whereKey($board->id)->lockForUpdate()->firstOrFail();
            $ids = array_column($elements, 'id');
            $stored = $locked->whiteboardElements()->whereIn('element_id', $ids)->get()->keyBy('element_id');

            $accepted = [];
            $stale = [];

            foreach ($elements as $element) {
                $current = $stored->get($element['id']);

                if ($force) {
                    $element['version'] = max((int) $element['version'], ($current?->version ?? 0) + 1);
                    $element['versionNonce'] = random_int(1, 2_147_483_646);
                    $element['updated'] = now()->getTimestampMs();
                    $accepted[$element['id']] = $element;

                    continue;
                }

                if ($current === null || $this->wins($element, $current)) {
                    $accepted[$element['id']] = $element;
                } elseif (! ($current->version === $element['version'] && $current->version_nonce === $element['versionNonce'])) {
                    // O navegador ficou para trás: devolve o que está guardado para ele juntar.
                    $stale[] = $current->element();
                }
            }

            if ($accepted === []) {
                return ['seq' => $locked->whiteboard_seq, 'saved' => [], 'stale' => $stale];
            }

            $created = count(array_filter($accepted, fn ($element) => ! $stored->has($element['id']) && ! $element['isDeleted']));
            $max = (int) config('ward.whiteboards.max_elements');

            if ($created > 0 && $locked->whiteboardElements()->where('is_deleted', false)->count() + $created > $max) {
                throw new InvalidWhiteboard("O quadro chegou ao limite de {$max} elementos.");
            }

            $seq = $locked->whiteboard_seq + 1;
            $now = now();

            foreach (array_chunk($accepted, 200) as $chunk) {
                WhiteboardElement::upsert(array_map(fn ($element) => [
                    'artifact_id' => $locked->id,
                    'element_id' => $element['id'],
                    'type' => $element['type'],
                    'version' => $element['version'],
                    'version_nonce' => $element['versionNonce'],
                    'is_deleted' => $element['isDeleted'],
                    'data' => json_encode($element, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'seq' => $seq,
                    'updated_by' => $user?->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk), ['artifact_id', 'element_id'], ['type', 'version', 'version_nonce', 'is_deleted', 'data', 'seq', 'updated_by', 'updated_at']);
            }

            $locked->forceFill(['whiteboard_seq' => $seq, 'size_bytes' => $this->size($locked)])->save();
            $board->whiteboard_seq = $seq;

            return ['seq' => $seq, 'saved' => array_keys($accepted), 'stale' => $stale];
        });
    }

    /**
     * @param  array<string, mixed>  $incoming
     */
    private function wins(array $incoming, WhiteboardElement $current): bool
    {
        return $incoming['version'] > $current->version
            || ($incoming['version'] === $current->version && $incoming['versionNonce'] < $current->version_nonce);
    }

    /**
     * Sobe o relógio sem mudar elementos (votação, cronômetro, comentários): quem
     * está com o quadro aberto busca de novo e vê.
     */
    public function touch(Artifact $board): int
    {
        return DB::transaction(function () use ($board) {
            $locked = Artifact::whereKey($board->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['whiteboard_seq' => $locked->whiteboard_seq + 1])->save();

            return $board->whiteboard_seq = $locked->whiteboard_seq;
        });
    }

    /**
     * Elementos que mudaram depois de $since (inclusive os apagados, para o
     * navegador também apagar).
     *
     * @return list<array<string, mixed>>
     */
    public function since(Artifact $board, int $since): array
    {
        return $board->whiteboardElements()->where('seq', '>', $since)->orderBy('seq')->orderBy('id')
            ->pluck('data')->map(fn ($data) => json_decode($data, true))->all();
    }

    /**
     * O quadro inteiro (sem os apagados), na ordem de empilhamento do Excalidraw
     * (índice fracionário, comparado byte a byte; sem índice vai para o topo).
     *
     * @return list<array<string, mixed>>
     */
    public function all(Artifact $board): array
    {
        $elements = $board->whiteboardElements()->where('is_deleted', false)->orderBy('id')
            ->pluck('data')->map(fn ($data) => json_decode($data, true))->all();

        usort($elements, function ($a, $b) {
            $ia = $a['index'] ?? null;
            $ib = $b['index'] ?? null;

            return match (true) {
                $ia === $ib => 0,
                $ia === null => 1,
                $ib === null => -1,
                default => strcmp($ia, $ib),
            };
        });

        return $elements;
    }

    /**
     * Um elemento guardado (não apagado).
     *
     * @return array<string, mixed>|null
     */
    public function find(Artifact $board, string $id): ?array
    {
        $data = $board->whiteboardElements()->where('element_id', $id)->where('is_deleted', false)->value('data');

        return $data ? json_decode($data, true) : null;
    }

    /**
     * Quem ficou para trás de uma limpeza de apagados recebe o quadro inteiro.
     */
    public function needsFull(Artifact $board, int $since): bool
    {
        return $since <= 0 || $since < (int) ($board->whiteboard_state['purged_seq'] ?? 0);
    }

    /**
     * Remove do banco os elementos apagados há mais de ward.whiteboards.purge_deleted_days.
     */
    public function purge(Artifact $board): int
    {
        $old = $board->whiteboardElements()->where('is_deleted', true)
            ->where('updated_at', '<', now()->subDays((int) config('ward.whiteboards.purge_deleted_days')));

        $maxSeq = (int) (clone $old)->max('seq');
        $count = $old->delete();

        if ($count > 0) {
            $state = $board->whiteboard_state ?? [];
            $state['purged_seq'] = max((int) ($state['purged_seq'] ?? 0), $maxSeq);
            $board->forceFill(['whiteboard_state' => $state])->save();
        }

        return $count;
    }

    private function size(Artifact $board): int
    {
        return (int) $board->whiteboardElements()->where('is_deleted', false)->sum(DB::raw('LENGTH(data)'))
            + (int) $board->whiteboardFiles()->sum('size_bytes');
    }
}
