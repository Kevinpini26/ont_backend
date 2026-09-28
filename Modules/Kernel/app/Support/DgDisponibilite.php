<?php

namespace Modules\Kernel\Support;

use Modules\Kernel\Models\DgInterim;

/**
 * Lecture de compatibilité de l'état canonique dg_interims. L'ancien
 * booléen users.dg_disponible ne constitue jamais une preuve d'autorité.
 */
class DgDisponibilite
{
    public static function estDisponible(): bool
    {
        return ! DgInterim::query()->whereNull('ended_at')->exists();
    }

    public static function definir(bool $disponible): void
    {
        throw new \LogicException('Un intérim DG doit être ouvert ou terminé par DgInterimService avec motif et acteur.');
    }
}
