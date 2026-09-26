<?php

namespace Modules\Courrier\Contracts;

/**
 * Point d'extension : fournit le prochain numéro de séquence, atomiquement,
 * pour un type de référence et une année donnés (ex: accusés de réception,
 * numéros d'enregistrement).
 */
interface SequenceGenerator
{
    public function suivant(string $type, int $annee): int;

    /**
     * Séquence indépendante par direction et par année. La direction est
     * explicite : elle ne doit jamais être encodée implicitement dans le type.
     */
    public function suivantPourDirection(string $type, int $annee, int $directionId): int;
}
