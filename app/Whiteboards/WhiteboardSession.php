<?php

namespace App\Whiteboards;

use App\Models\Artifact;
use App\Models\User;
use App\Models\WhiteboardVote;
use Illuminate\Support\Facades\DB;

/**
 * Dinâmicas de reunião no quadro: votação em elementos (cada pessoa tem N votos)
 * e cronômetro compartilhado. Ficam em artifacts.whiteboard_state e nos votos;
 * cada mudança sobe o seq, então todos veem pelo ao vivo.
 */
class WhiteboardSession
{
    public function __construct(private WhiteboardStore $store) {}

    /**
     * @return array<string, mixed>
     */
    public function state(Artifact $board, ?User $user = null): array
    {
        $state = $board->whiteboard_state ?? [];
        $voting = $state['voting'] ?? null;

        if ($voting !== null) {
            $votes = WhiteboardVote::where('artifact_id', $board->id)->get();
            $voting['totals'] = $votes->groupBy('element_id')->map(fn ($group) => (int) $group->sum('count'))->all();
            $voting['mine'] = $user ? $votes->where('user_id', $user->id)->pluck('count', 'element_id')->map(fn ($count) => (int) $count)->all() : [];
            $voting['used'] = array_sum($voting['mine']);
            $voting['voters'] = $votes->pluck('user_id')->unique()->count();
        }

        return array_filter([
            'voting' => $voting,
            'timer' => $state['timer'] ?? null,
        ], fn ($value) => $value !== null);
    }

    public function startVoting(Artifact $board, User $user, int $votesPerPerson): void
    {
        $this->setState($board, 'voting', [
            'open' => true,
            'max' => max(1, min(20, $votesPerPerson)),
            'started_by' => $user->name,
            'started_at' => now()->getTimestampMs(),
        ]);
        WhiteboardVote::where('artifact_id', $board->id)->delete();
        $this->store->touch($board);
    }

    public function closeVoting(Artifact $board): void
    {
        $voting = ($board->whiteboard_state ?? [])['voting'] ?? null;

        if ($voting === null) {
            return;
        }

        $voting['open'] = false;
        $this->setState($board, 'voting', $voting);
        $this->store->touch($board);
    }

    public function clearVoting(Artifact $board): void
    {
        WhiteboardVote::where('artifact_id', $board->id)->delete();
        $this->setState($board, 'voting', null);
        $this->store->touch($board);
    }

    /**
     * +1 ou -1 voto num elemento. Recusa acima do limite por pessoa.
     */
    public function vote(Artifact $board, User $user, string $elementId, int $delta): void
    {
        $voting = ($board->whiteboard_state ?? [])['voting'] ?? null;

        if (! ($voting['open'] ?? false)) {
            throw new InvalidWhiteboard('Não há votação aberta.');
        }

        if ($this->store->find($board, $elementId) === null) {
            throw new InvalidWhiteboard('Este elemento não existe mais.');
        }

        DB::transaction(function () use ($board, $user, $elementId, $delta, $voting) {
            $used = (int) WhiteboardVote::where('artifact_id', $board->id)->where('user_id', $user->id)->lockForUpdate()->sum('count');
            $vote = WhiteboardVote::firstOrNew(['artifact_id' => $board->id, 'element_id' => $elementId, 'user_id' => $user->id]);

            if ($delta > 0 && $used >= $voting['max']) {
                throw new InvalidWhiteboard("Você já usou seus {$voting['max']} votos.");
            }

            $vote->count = max(0, (int) $vote->count + ($delta > 0 ? 1 : -1));
            $vote->count === 0 ? ($vote->exists && $vote->delete()) : $vote->save();
        });

        $this->store->touch($board);
    }

    /**
     * Cronômetro: start (segundos), pause, resume, stop.
     */
    public function timer(Artifact $board, User $user, string $action, int $seconds = 300): void
    {
        $timer = ($board->whiteboard_state ?? [])['timer'] ?? null;
        $now = now()->getTimestampMs();

        $timer = match ($action) {
            'start' => ['running' => true, 'duration' => max(10, min(7200, $seconds)) * 1000, 'ends_at' => $now + max(10, min(7200, $seconds)) * 1000, 'remaining' => null, 'by' => $user->name],
            'pause' => $timer && $timer['running'] ? ['running' => false, 'remaining' => max(0, $timer['ends_at'] - $now), 'ends_at' => null] + $timer : $timer,
            'resume' => $timer && ! $timer['running'] ? ['running' => true, 'ends_at' => $now + (int) $timer['remaining'], 'remaining' => null] + $timer : $timer,
            'stop' => null,
            default => throw new InvalidWhiteboard('Ação do cronômetro inválida.'),
        };

        $this->setState($board, 'timer', $timer);
        $this->store->touch($board);
    }

    private function setState(Artifact $board, string $key, mixed $value): void
    {
        DB::transaction(function () use ($board, $key, $value) {
            $locked = Artifact::whereKey($board->id)->lockForUpdate()->firstOrFail();
            $state = $locked->whiteboard_state ?? [];

            if ($value === null) {
                unset($state[$key]);
            } else {
                $state[$key] = $value;
            }

            $locked->forceFill(['whiteboard_state' => $state ?: null])->save();
            $board->whiteboard_state = $locked->whiteboard_state;
        });
    }
}
