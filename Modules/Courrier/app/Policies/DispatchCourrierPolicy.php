<?php

namespace Modules\Courrier\Policies;

use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\ModeSortie;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;

class DispatchCourrierPolicy
{
    public function __construct(private readonly DelegationResolver $delegations) {}

    public function view(User $user, DispatchCourrier $dispatch): bool
    {
        if ($this->delegations->utilisateurHabilite($user, [Poste::DG, Poste::DGA, Poste::SECRETARIAT_2])) {
            return true;
        }

        return $dispatch->type_destination === DispatchTypeDestination::DIRECTION
            && $dispatch->statut === DispatchStatut::EXECUTE
            && $user->role === UserRole::SECRETARIAT_DIRECTION
            && $user->direction_id === $dispatch->direction_id;
    }

    public function executer(User $user, DispatchCourrier $dispatch): bool
    {
        return $dispatch->statut === DispatchStatut::EN_ATTENTE
            && $this->delegations->utilisateurHabilite($user, [Poste::SECRETARIAT_2]);
    }

    public function accuserReception(User $user, DispatchCourrier $dispatch): bool
    {
        if ($dispatch->type_destination === DispatchTypeDestination::CLASSEMENT) {
            $courrier = $dispatch->courrier;

            return $dispatch->statut === DispatchStatut::EN_ATTENTE
                && $dispatch->accuse_reception_at === null
                && $courrier !== null
                && in_array($courrier->mode_sortie, [ModeSortie::COURRIEL, ModeSortie::RETRAIT_PHYSIQUE, ModeSortie::COURRIEL_ET_RETRAIT], true)
                && $courrier->estSortieCompletee()
                && $courrier->estCycleClassementApresSortieCompletee()
                && $dispatch->cycle === (int) $courrier->dispatchs()->max('cycle')
                && $this->delegations->utilisateurHabilite($user, [Poste::SECRETARIAT_2]);
        }

        return $dispatch->type_destination === DispatchTypeDestination::DIRECTION
            && $dispatch->statut === DispatchStatut::EXECUTE
            && $dispatch->accuse_reception_at === null
            && $user->role === UserRole::SECRETARIAT_DIRECTION
            && $user->direction_id === $dispatch->direction_id;
    }
}
