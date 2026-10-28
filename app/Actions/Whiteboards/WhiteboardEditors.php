<?php

namespace App\Actions\Whiteboards;

use App\Models\Artifact;
use App\Models\ArtifactMember;
use App\Models\User;
use App\Models\WhiteboardPresence;
use App\Whiteboards\InvalidWhiteboard;

/**
 * Convidados editores de um whiteboard: o dono (ou um admin) convida pelo
 * username ou e-mail de quem já tem conta, e remove quando quiser. Removido,
 * a próxima gravação da pessoa recebe 403 e o quadro dela fica só leitura.
 */
class WhiteboardEditors
{
    public function invite(Artifact $board, User $inviter, string $identifier): ArtifactMember
    {
        if (! $board->isWhiteboard()) {
            throw new InvalidWhiteboard('Só whiteboards têm convidados.');
        }

        $identifier = ltrim(trim($identifier), '@');
        $user = User::where('username', $identifier)->orWhere('email', $identifier)->first();

        if ($user === null) {
            throw new InvalidWhiteboard('Nenhuma conta com esse username ou e-mail. Quem ainda não tem conta precisa ser convidado por um admin primeiro.');
        }

        if ($user->id === $board->user_id) {
            throw new InvalidWhiteboard('Esta pessoa é a dona do quadro.');
        }

        return ArtifactMember::updateOrCreate(
            ['artifact_id' => $board->id, 'user_id' => $user->id],
            ['role' => ArtifactMember::EDITOR, 'invited_by' => $inviter->id],
        );
    }

    public function remove(Artifact $board, int $userId): void
    {
        $board->members()->where('user_id', $userId)->delete();
        WhiteboardPresence::where('artifact_id', $board->id)->where('user_id', $userId)->delete();
    }
}
