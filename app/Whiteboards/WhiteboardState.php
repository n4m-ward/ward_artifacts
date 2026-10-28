<?php

namespace App\Whiteboards;

use App\Artifacts\ContentGateway;
use App\Models\Artifact;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\URL;

/**
 * O que a página do quadro recebe: a configuração (rotas, permissões, quem é a
 * pessoa) e o pacote do ao vivo (elementos, imagens, votação/cronômetro,
 * comentários, quem está no quadro e onde está a IA).
 */
class WhiteboardState
{
    public function __construct(
        private WhiteboardStore $store,
        private WhiteboardFiles $files,
        private WhiteboardSession $session,
        private WhiteboardComments $comments,
        private WhiteboardPresence $presence,
    ) {}

    /**
     * Pacote do ao vivo a partir de $since (0 = o quadro inteiro).
     *
     * @return array<string, mixed>
     */
    public function live(Artifact $board, int $since, ?User $user): array
    {
        $full = $this->store->needsFull($board, $since);

        return [
            'seq' => $board->whiteboard_seq,
            'full' => $full,
            'elements' => $full ? $this->store->all($board) : $this->store->since($board, $since),
            'files' => $this->files->urls($board),
            'session' => (object) $this->session->state($board, $user),
            // Comentários só para quem está logado (nunca no link público).
            'comments' => $user ? $this->comments->threads($board) : null,
            'people' => $this->presence->people($board, $user),
            'ai' => $this->presence->ai($board),
            'presence' => $this->presence->signature($board, $user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function config(Artifact $board, ?User $user, bool $preview = false, ?string $csrf = null, bool $withInitial = true): array
    {
        $liveUntil = now()->addMinutes((int) config('ward.artifacts.preview_session_minutes'));

        if (! $preview && $user === null && $board->public_until !== null) {
            $liveUntil = $liveUntil->min($board->public_until);
        }

        $viewer = $preview ? null : $user;
        $canEdit = $viewer?->can('edit', $board) ?? false;
        $uuid = $board->uuid;

        return [
            'board' => $uuid,
            'title' => $board->title,
            'url' => $board->url(),
            'preview' => $preview,
            'canEdit' => $canEdit,
            'canManage' => $viewer?->can('update', $board) ?? false,
            'user' => $viewer ? ['id' => 'u'.$viewer->id, 'name' => $viewer->name, 'color' => WhiteboardPresence::colorFor($viewer->id)] : null,
            'liveUrl' => $this->liveUrl($board, $liveUntil),
            'csrf' => $viewer ? $csrf : null,
            'routes' => $viewer ? array_filter([
                'elements' => $canEdit ? route('whiteboards.elements', $uuid) : null,
                'files' => $canEdit ? route('whiteboards.files.store', $uuid) : null,
                'presence' => route('whiteboards.presence', $uuid),
                'comments' => route('whiteboards.comments.store', $uuid),
                'comment' => route('whiteboards.comments.update', [$uuid, 0]),
                'votes' => route('whiteboards.votes', $uuid),
                'voting' => $canEdit ? route('whiteboards.voting', $uuid) : null,
                'timer' => $canEdit ? route('whiteboards.timer', $uuid) : null,
                'versions' => route('whiteboards.versions.index', $uuid),
                'saveVersion' => $canEdit ? route('whiteboards.versions.store', $uuid) : null,
                'restoreVersion' => $canEdit ? route('whiteboards.versions.restore', [$uuid, 0]) : null,
                'template' => $canEdit ? route('whiteboards.template', $uuid) : null,
                'artboards' => $canEdit ? route('whiteboards.artboards', $uuid) : null,
                'download' => route('artifacts.download', $board),
            ]) : [],
            'templates' => WhiteboardTemplates::NAMES,
            'assetPath' => asset('build/excalidraw').'/',
            'appOrigin' => rtrim(url('/'), '/'),
            'embedHosts' => array_values((array) config('ward.whiteboards.embed_hosts')),
            // Artboards do Ward Design no quadro: mesmo sandbox dos iframes do canvas de design.
            'artboardSandbox' => app(ContentGateway::class)->sandbox(),
            'initial' => $withInitial ? $this->live($board, 0, $viewer) : null,
        ];
    }

    /**
     * Configuração do quadro no MCP App: só leitura (sem cookie no iframe do chat),
     * ao vivo pela URL assinada, com o horário em que foi emitida.
     *
     * @return array<string, mixed>
     */
    public function appConfig(Artifact $board, bool $withInitial = true): array
    {
        $config = ['app' => true, 'issuedAt' => now()->getTimestampMs()] + $this->config($board, null, preview: true, withInitial: $withInitial);

        return $config;
    }

    public function liveUrl(Artifact $board, DateTimeInterface $expires): string
    {
        return URL::temporarySignedRoute('whiteboards.live', $expires, ['artifact' => $board->uuid]);
    }
}
