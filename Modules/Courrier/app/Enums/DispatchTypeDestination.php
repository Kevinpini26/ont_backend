<?php

namespace Modules\Courrier\Enums;

enum DispatchTypeDestination: string
{
    case DIRECTION = 'direction';
    case EXTERIEUR = 'exterieur';
    case CLASSEMENT = 'classement';

    public function label(): string
    {
        return match ($this) {
            self::DIRECTION => 'Direction interne',
            self::EXTERIEUR => 'Extérieur',
            self::CLASSEMENT => 'Classement',
        };
    }
}
