<?php

namespace Modules\Courrier\Policies;

use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;

class MissionDocumentairePolicy
{
    public function __construct(private readonly DelegationResolver $delegations) {}

    public function view(User $user, MissionDocumentaire $mission): bool
    {
        return $mission->assistant_id === $user->id
            || $mission->demandeur_id === $user->id
            || $this->delegations->utilisateurHabilite($user, [$mission->autorite_poste]);
    }

    public function prendreEnCharge(User $user, MissionDocumentaire $mission): bool
    {
        return $mission->assistant_id === $user->id;
    }

    public function retourner(User $user, MissionDocumentaire $mission): bool
    {
        return $mission->assistant_id === $user->id;
    }

    public function annuler(User $user, MissionDocumentaire $mission): bool
    {
        return $mission->demandeur_id === $user->id
            || $this->delegations->utilisateurHabilite($user, [$mission->autorite_poste]);
    }
}
