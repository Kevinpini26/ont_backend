<?php

namespace Modules\Courrier\Enums;

enum ConfiageDgaStatut: string
{
    case EN_COURS = 'en_cours';
    case RETOURNE = 'retourne';
    case REPRIS = 'repris';

    public function label(): string
    {
        return match ($this) {
            self::EN_COURS => 'Confié au DGA',
            self::RETOURNE => 'Retourné au DG',
            self::REPRIS => 'Repris par le DG',
        };
    }
}
