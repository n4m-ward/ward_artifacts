<?php

namespace App\Policies;

use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\ArtifactMember;
use App\Models\User;

class ArtifactPolicy
{
    /**
     * Público (dentro do prazo) → qualquer pessoa.
     * Usuários logados → qualquer conta.
     * Só eu → o dono e os convidados. O admin sempre vê.
     */
    public function view(?User $user, Artifact $artifact): bool
    {
        if ($artifact->isPublic()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if ($this->manages($user, $artifact)) {
            return true;
        }

        return $artifact->visibility === Visibility::Users || $artifact->isMember($user);
    }

    /**
     * Renomear, mudar a visibilidade, gerenciar convidados e enviar nova versão.
     */
    public function update(User $user, Artifact $artifact): bool
    {
        return $this->manages($user, $artifact);
    }

    /**
     * Mexer no conteúdo de um whiteboard: dono, admin ou convidado editor.
     * Nos outros tipos é o mesmo que update.
     */
    public function edit(User $user, Artifact $artifact): bool
    {
        if ($this->manages($user, $artifact)) {
            return true;
        }

        return $artifact->isWhiteboard() && $artifact->isMember($user, ArtifactMember::EDITOR);
    }

    public function delete(User $user, Artifact $artifact): bool
    {
        return $this->manages($user, $artifact);
    }

    private function manages(User $user, Artifact $artifact): bool
    {
        return $user->isAdmin() || $user->id === $artifact->user_id;
    }
}
