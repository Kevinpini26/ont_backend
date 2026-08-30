<?php

namespace Modules\Kernel\Support;

use Modules\Kernel\Contracts\DirectionScopeBypassResolver;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;

/**
 * Règle par défaut : l'administrateur et la DFP voient toutes les directions,
 * ainsi que les postes du circuit courrier central, dont la liste exacte est
 * configurable via config('kernel.circuit_courrier_central_postes').
 */
class DefaultDirectionScopeBypassResolver implements DirectionScopeBypassResolver
{
    public function __construct(private readonly DelegationResolver $delegations) {}

    public function bypasses(User $user): bool
    {
        $bypassePosteOccupe = match ($user->role) {
            UserRole::ADMINISTRATEUR, UserRole::AGENT_DFP => true,
            UserRole::AGENT_CIRCUIT_COURRIER => in_array(
                $user->poste?->value,
                config('kernel.circuit_courrier_central_postes', []),
                strict: true,
            ),
            default => false,
        };

        if ($bypassePosteOccupe) {
            return true;
        }

        // Un utilisateur délégué sur un poste du circuit central doit voir
        // les mêmes dossiers que le titulaire du poste, sans quoi il ne
        // peut matériellement pas agir sur ce qu'il ne voit pas (voir
        // DelegationResolver).
        $posteDelegue = $this->delegations->posteDelegueAujourdhui($user);

        return $posteDelegue !== null && in_array(
            $posteDelegue->value,
            config('kernel.circuit_courrier_central_postes', []),
            strict: true,
        );
    }
}
