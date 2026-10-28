<?php

namespace App\Whiteboards;

use App\Models\Artifact;
use App\Models\User;
use App\Models\WhiteboardPresence as Row;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Quem está no quadro agora. Cada pessoa logada manda cursor, seleção e câmera
 * (no máximo a cada ~500 ms); o ao vivo devolve a lista dos outros e o navegador
 * desenha os cursores pelo `collaborators` do Excalidraw.
 *
 * A IA também aparece, com um cursor próprio: as ferramentas MCP gravam aqui onde
 * ela está mexendo, e show_in_canvas leva a câmera de quem está seguindo.
 */
class WhiteboardPresence
{
    public const COLORS = ['#e03131', '#1971c2', '#2f9e44', '#f08c00', '#6741d9', '#c2255c', '#0c8599', '#e8590c', '#5f3dc4', '#099268'];

    public const AI_COLOR = '#7048e8';

    public const AI_ACTIONS = ['focus', 'fit', 'point', 'editing', 'reading'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function report(Artifact $board, User $user, array $data): void
    {
        $pointer = is_array($data['pointer'] ?? null) && is_numeric($data['pointer']['x'] ?? null) && is_numeric($data['pointer']['y'] ?? null)
            ? ['x' => round((float) $data['pointer']['x'], 1), 'y' => round((float) $data['pointer']['y'], 1)]
            : null;
        $view = is_array($data['view'] ?? null) ? array_map(fn ($value) => round((float) $value, 1), array_intersect_key(
            array_filter($data['view'], 'is_numeric'), array_flip(['x', 'y', 'w', 'h'])
        )) : null;
        $selected = array_values(array_slice(array_filter((array) ($data['selected'] ?? []), fn ($id) => is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)), 0, 50));

        Row::updateOrCreate(['artifact_id' => $board->id, 'user_id' => $user->id], [
            'data' => array_filter([
                'pointer' => $pointer,
                'button' => ($data['button'] ?? null) === 'down' ? 'down' : 'up',
                'selected' => $selected,
                'view' => count((array) $view) === 4 ? $view : null,
                'tool' => is_string($data['tool'] ?? null) ? Str::limit($data['tool'], 20, '') : null,
                'editing' => is_string($data['editing'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $data['editing']) ? $data['editing'] : null,
            ], fn ($value) => $value !== null && $value !== []),
            'seen_at' => now(),
        ]);
    }

    public function leave(Artifact $board, User $user): void
    {
        Row::where('artifact_id', $board->id)->where('user_id', $user->id)->delete();
    }

    /**
     * Pessoas no quadro (sem quem pergunta).
     *
     * @return list<array<string, mixed>>
     */
    public function people(Artifact $board, ?User $except = null): array
    {
        return Row::with('user')->where('artifact_id', $board->id)
            ->where('seen_at', '>=', now()->subSeconds((int) config('ward.whiteboards.presence_ttl_seconds')))
            ->when($except, fn ($query) => $query->where('user_id', '!=', $except->id))
            ->orderBy('user_id')->limit(50)->get()
            ->map(fn (Row $row) => [
                'id' => 'u'.$row->user_id,
                'name' => $row->user?->name ?? 'Pessoa',
                'username' => $row->user?->username,
                'color' => self::colorFor($row->user_id),
                'at' => $row->seen_at->getTimestampMs(),
            ] + $row->data)->values()->all();
    }

    /**
     * Assinatura de quem está no quadro: o ao vivo responde 204 quando ela e o seq não mudaram.
     */
    public function signature(Artifact $board, ?User $except = null): string
    {
        $last = Row::where('artifact_id', $board->id)
            ->where('seen_at', '>=', now()->subSeconds((int) config('ward.whiteboards.presence_ttl_seconds')))
            ->when($except, fn ($query) => $query->where('user_id', '!=', $except->id))
            ->selectRaw('COUNT(*) as people, MAX(seen_at) as last')->first();

        return substr(md5(($last->people ?? 0).'|'.($last->last ?? '').'|'.($this->ai($board)['seq'] ?? 0)), 0, 12);
    }

    public static function colorFor(int $userId): string
    {
        return self::COLORS[$userId % count(self::COLORS)];
    }

    /**
     * Onde a IA está: action (focus, fit, point, editing, reading), elementos, um
     * ponto para o cursor e uma frase curta.
     *
     * @param  array{action: string, elements?: list<string>, frame?: ?string, point?: ?array{x: float, y: float}, message?: ?string}  $data
     * @return array<string, mixed>
     */
    public function pushAi(Artifact $board, string $actor, array $data): array
    {
        $presence = array_filter([
            'seq' => max((int) floor(microtime(true) * 1000), ($this->ai($board)['seq'] ?? 0) + 1),
            'actor' => $actor,
            'action' => $data['action'],
            'elements' => array_values(array_slice($data['elements'] ?? [], 0, 200)),
            'frame' => $data['frame'] ?? null,
            'point' => $data['point'] ?? null,
            'message' => isset($data['message']) ? Str::limit((string) $data['message'], 140, '') : null,
            'at' => now()->getTimestampMs(),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        Cache::put($this->aiKey($board), $presence, now()->addMinutes(30));

        return $presence;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function ai(Artifact $board): ?array
    {
        $presence = Cache::get($this->aiKey($board));

        return is_array($presence) ? $presence : null;
    }

    /**
     * O que cada pessoa está selecionando agora (para get_whiteboard).
     *
     * @return list<array{name: string, selected: list<string>, view?: array<string, float>}>
     */
    public function viewing(Artifact $board): array
    {
        return array_map(fn ($person) => array_filter([
            'name' => $person['name'],
            'selected' => $person['selected'] ?? [],
            'view' => $person['view'] ?? null,
            'editing' => $person['editing'] ?? null,
        ], fn ($value) => $value !== null), $this->people($board));
    }

    /** Um quadro buscou o ao vivo agora (há alguém vendo, logado ou não). */
    public function touchViewer(Artifact $board): void
    {
        Cache::put($this->aiKey($board).':viewer', now()->getTimestampMs(), now()->addMinutes(2));
    }

    public function hasViewers(Artifact $board, int $withinSeconds = 15): bool
    {
        $last = Cache::get($this->aiKey($board).':viewer');

        return is_int($last) && now()->getTimestampMs() - $last <= $withinSeconds * 1000;
    }

    private function aiKey(Artifact $board): string
    {
        return "whiteboard-ai:{$board->id}";
    }
}
