<?php

namespace Modules\Courrier\Policies;

use Modules\Courrier\Models\BordereauLot;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;

/**
 * Filtre grossier par poste, comme CourrierPolicy — la garde précise
 * (bordereau déjà acquitté) reste dans CourrierCircuitService.
 */
class BordereauLotPolicy
{
    public function __construct(private readonly DelegationResolver $delegations) {}

    /**
     * Grouper des transitions déjà en attente ne change aucun état
     * (voir CourrierCircuitService::grouperEnBordereauLot()) — le service
     * vérifie déjà que les transitions existent et partagent le même
     * poste destinataire, donc ouvert à tout poste du circuit central
     * plutôt que restreint à la Réception (qui n'est en pratique jamais
     * elle-même destinataire de ces transitions, voir docs/questions-ont.md).
     */
    public function creer(User $user): bool
    {
        return $user->role === UserRole::ADMINISTRATEUR
            || ($user->role === UserRole::AGENT_CIRCUIT_COURRIER && ! $user->poste?->estHistorique());
    }

    public function view(User $user, BordereauLot $bordereau): bool
    {
        return $user->role === UserRole::ADMINISTRATEUR
            || $user->id === $bordereau->emetteur_id
            || $this->delegations->utilisateurHabilite($user, [$bordereau->poste_destinataire]);
    }

    public function accuserReception(User $user, BordereauLot $bordereau): bool
    {
        return $user->role === UserRole::ADMINISTRATEUR
            || $this->delegations->utilisateurHabilite($user, [$bordereau->poste_destinataire]);
    }
}
