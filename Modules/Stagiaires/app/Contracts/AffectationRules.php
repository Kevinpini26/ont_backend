<?php

namespace Modules\Stagiaires\Contracts;

use Illuminate\Support\Collection;

/**
 * Point d'extension : détermine les directions éligibles à l'affectation
 * d'un stagiaire (par défaut, les huit directions actives).
 */
interface AffectationRules
{
    public function directionsEligibles(): Collection;

    public function estEligible(int $directionId): bool;

    /**
     * Quota : la direction a-t-elle déjà atteint sa capacité maximale de
     * stagiaires actifs (affectés ou en stage) ? Toujours faux si la
     * direction n'a pas de `capacite_max` défini (pas de limite).
     */
    public function estSaturee(int $directionId): bool;

    /**
     * Lot A (avertissement non bloquant à la composition du tableau) :
     * l'occupation actuelle et la capacité déclarée, pour juger si une
     * direction est "proche" de sa limite sans attendre qu'elle soit
     * atteinte — voir Support\AvertissementsLigneTableau.
     *
     * @return array{occupation: int, capacite: ?int}
     */
    public function occupationEtCapacite(int $directionId): array;
}
