<?php

namespace Modules\Courrier\Policies;

use Modules\Courrier\Enums\TraitementDirectionStatut;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;

class TraitementDirectionPolicy
{
    public function view(User $user, TraitementDirection $traitement): bool
    {
        return $user->direction_id === $traitement->direction_id
            && ($user->role === UserRole::SECRETARIAT_DIRECTION
                || $user->role === UserRole::DIRECTEUR_DIRECTION
                || $user->role === UserRole::RESPONSABLE_DIRECTION);
    }

    public function transmettreDirecteur(User $user, TraitementDirection $traitement): bool
    {
        return $user->role === UserRole::SECRETARIAT_DIRECTION
            && $user->direction_id === $traitement->direction_id
            && $traitement->statut === TraitementDirectionStatut::RECU_SECRETARIAT;
    }

    public function prendreEnCharge(User $user, TraitementDirection $traitement): bool
    {
        return $this->estDirecteurDesigne($user, $traitement)
            && $traitement->statut === TraitementDirectionStatut::TRANSMIS_DIRECTEUR;
    }

    public function decider(User $user, TraitementDirection $traitement): bool
    {
        return $this->estDirecteurDesigne($user, $traitement)
            && $traitement->statut === TraitementDirectionStatut::EN_TRAITEMENT;
    }

    private function estDirecteurDesigne(User $user, TraitementDirection $traitement): bool
    {
        return $user->id === $traitement->directeur_id
            && $user->direction_id === $traitement->direction_id
            && in_array($user->role, [UserRole::DIRECTEUR_DIRECTION, UserRole::RESPONSABLE_DIRECTION], true);
    }
}
