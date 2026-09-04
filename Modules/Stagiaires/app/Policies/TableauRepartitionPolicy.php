<?php

namespace Modules\Stagiaires\Policies;

use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Models\TableauRepartition;

/**
 * Filtre grossier par rôle/poste, comme CourrierPolicy/StagiairePolicy — la
 * vérification précise (statut du tableau, garde d'intérim DGA) reste dans
 * TableauRepartitionCircuitService.
 */
class TableauRepartitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::AGENT_DFP
            || $user->role === UserRole::ADMINISTRATEUR
            || in_array($user->poste, [Poste::DG, Poste::DGA, Poste::RECEPTION], true);
    }

    public function view(User $user, TableauRepartition $tableau): bool
    {
        return $this->viewAny($user);
    }

    public function creer(User $user): bool
    {
        return $user->role === UserRole::AGENT_DFP;
    }

    /**
     * Ajouter/retirer une ligne, ou soumettre — réservé à la DFP, le
     * rédacteur du tableau. La garde "tant que non soumis" (modifiable())
     * reste dans le service.
     */
    public function modifier(User $user, TableauRepartition $tableau): bool
    {
        return $user->role === UserRole::AGENT_DFP;
    }

    public function soumettre(User $user, TableauRepartition $tableau): bool
    {
        return $user->role === UserRole::AGENT_DFP;
    }

    /**
     * La DGA figure ici même si elle ne peut réellement agir que lorsque
     * la DG est indisponible (garde dynamique, voir
     * TableauRepartitionCircuitService::representerDg()/rendreAvis()) —
     * même principe que CourrierPolicy::transmettre().
     */
    public function representerDg(User $user, TableauRepartition $tableau): bool
    {
        return $user->poste === Poste::RECEPTION;
    }

    public function rendreAvis(User $user): bool
    {
        return $user->poste === Poste::DG || $user->poste === Poste::DGA;
    }
}
