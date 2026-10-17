<?php

namespace App\Policies;

use App\Enums\Visibility;
use App\Models\Artifact;
use App\Models\User;

class ArtifactPolicy
{
    /**
     * Público (dentro do prazo) → qualquer pessoa.
     * Usuários logados → qualquer conta.
     * Só eu → o dono. O admin sempre vê.
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

        return $artifact->visibility === Visibility::Users;
    }

    /**
     * Renomear, mudar a visibilidade e enviar nova versão.
     */
    public function update(User $user, Artifact $artifact): bool
    {
        return $this->manages($user, $artifact);
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
