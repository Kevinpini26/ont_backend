<?php

namespace Modules\Courrier\Policies;

use Modules\Courrier\Enums\MissionDocumentaireStatut;
use Modules\Courrier\Enums\MissionDocumentaireType;
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

    public function redigerProjet(User $user, MissionDocumentaire $mission): bool
    {
        return $mission->assistant_id === $user->id
            && $mission->type === MissionDocumentaireType::PREPARATION_REPONSE
            && in_array($mission->statut, [MissionDocumentaireStatut::ASSIGNEE, MissionDocumentaireStatut::EN_COURS], true);
    }

    public function annuler(User $user, MissionDocumentaire $mission): bool
    {
        return $mission->demandeur_id === $user->id
            || $this->delegations->utilisateurHabilite($user, [$mission->autorite_poste]);
    }
}
