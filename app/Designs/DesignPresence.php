<?php

namespace App\Designs;

use App\Models\Artifact;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Onde a IA está no canvas: o que ela mostra (foco numa tela, Play, clique),
 * com um cursor e uma frase para quem acompanha. Todo canvas aberto (página do
 * design, preview_url, MCP App) recebe isso pelo ao vivo e segue a câmera.
 *
 * Num clique de teste, o canvas que executou devolve o resultado (tela em que o
 * Play ficou, erros), e a ferramenta show_in_canvas espera por ele.
 */
class DesignPresence
{
    public const ACTIONS = ['focus', 'fit', 'point', 'play', 'click', 'back', 'close', 'editing', 'reading', 'layout'];

    private const TTL_MINUTES = 30;

    /**
     * @param  array{action: string, board?: ?string, boards?: list<string>, element?: ?string, message?: ?string}  $data
     * @return array<string, mixed>
     */
    public function push(Artifact $design, string $actor, array $data): array
    {
        $presence = array_filter([
            // Milissegundos: cresce sempre e dispensa contador compartilhado.
            'seq' => max((int) floor(microtime(true) * 1000), ($this->current($design)['seq'] ?? 0) + 1),
            'actor' => $actor,
            'action' => $data['action'],
            'board' => $data['board'] ?? null,
            'boards' => $data['boards'] ?? null,
            'element' => isset($data['element']) ? Str::limit((string) $data['element'], 200, '') : null,
            'message' => isset($data['message']) ? Str::limit((string) $data['message'], 140, '') : null,
            'at' => now()->getTimestampMs(),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        Cache::put($this->key($design), $presence, now()->addMinutes(self::TTL_MINUTES));

        return $presence;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(Artifact $design): ?array
    {
        $presence = Cache::get($this->key($design));

        return is_array($presence) ? $presence : null;
    }

    /**
     * Resultado de uma ação vindo de um canvas aberto. Vale o primeiro.
     *
     * @param  array<string, mixed>  $result
     */
    public function report(Artifact $design, int $seq, array $result): void
    {
        $key = $this->key($design).":result:{$seq}";

        if (($this->current($design)['seq'] ?? null) !== $seq || Cache::has($key)) {
            return;
        }

        $names = $design->boards()->pluck('name')->all();
        $known = fn ($name) => is_string($name) && in_array($name, $names, true);

        Cache::put($key, array_filter([
            'found' => (bool) ($result['found'] ?? false),
            'element' => is_array($result['element'] ?? null) ? array_filter([
                'tag' => preg_match('/^[a-z][a-z0-9-]{0,30}$/', (string) ($result['element']['tag'] ?? '')) ? $result['element']['tag'] : null,
                'text' => Str::limit((string) ($result['element']['text'] ?? ''), 80, ''),
            ]) : null,
            'screen' => $known($result['screen'] ?? null) ? $result['screen'] : null,
            'overlays' => array_values(array_filter((array) ($result['overlays'] ?? []), $known)),
            'navigated' => (bool) ($result['navigated'] ?? false),
            'errors' => array_values(array_map(fn ($error) => Str::limit((string) $error, 200, ''), array_slice((array) ($result['errors'] ?? []), 0, 5))),
            'note' => isset($result['note']) ? Str::limit((string) $result['note'], 200, '') : null,
        ], fn ($value) => $value !== null && $value !== []), now()->addMinutes(2));
    }

    /**
     * Espera o resultado de uma ação (o canvas busca o ao vivo a cada 1,5–2 s).
     *
     * @return array<string, mixed>|null
     */
    public function await(Artifact $design, int $seq, float $seconds): ?array
    {
        $key = $this->key($design).":result:{$seq}";
        $until = microtime(true) + $seconds;

        do {
            $result = Cache::get($key);
            if (is_array($result)) {
                return $result;
            }
            usleep(250_000);
        } while (microtime(true) < $until);

        return null;
    }

    /** Um canvas buscou o ao vivo agora (há alguém vendo). */
    public function touchViewer(Artifact $design): void
    {
        Cache::put($this->key($design).':viewer', now()->getTimestampMs(), now()->addMinutes(2));
    }

    public function hasViewers(Artifact $design, int $withinSeconds = 10): bool
    {
        $last = Cache::get($this->key($design).':viewer');

        return is_int($last) && now()->getTimestampMs() - $last <= $withinSeconds * 1000;
    }

    /**
     * Nome mostrado no cursor: o cliente MCP conectado (Claude, Claude Code, WardCode).
     */
    public static function actorFor(?User $user): string
    {
        try {
            $client = (string) ($user?->token()?->client?->name ?? '');
        } catch (Throwable) {
            $client = '';
        }

        return match (true) {
            str_contains(strtolower($client), 'wardcode') || str_contains(strtolower($client), 'ward code') => 'WardCode',
            str_contains(strtolower($client), 'claude code') => 'Claude Code',
            str_contains(strtolower($client), 'claude') => 'Claude',
            $client !== '' && $client !== 'Ward Artifacts' => Str::limit($client, 24, ''),
            default => 'IA',
        };
    }

    private function key(Artifact $design): string
    {
        return "design-presence:{$design->id}";
    }
}
