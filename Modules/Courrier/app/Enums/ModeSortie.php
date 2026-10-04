<?php

namespace Modules\Courrier\Enums;

enum ModeSortie: string
{
    case COURRIEL = 'courriel';
    case RETRAIT_PHYSIQUE = 'retrait_physique';
    case COURRIEL_ET_RETRAIT = 'courriel_et_retrait';

    public function label(): string
    {
        return match ($this) {
            self::COURRIEL => 'Courriel',
            self::RETRAIT_PHYSIQUE => 'Retrait physique',
            self::COURRIEL_ET_RETRAIT => 'Courriel + retrait',
        };
    }
}
