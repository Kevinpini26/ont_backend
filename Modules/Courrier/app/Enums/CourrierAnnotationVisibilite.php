<?php

namespace Modules\Courrier\Enums;

enum CourrierAnnotationVisibilite: string
{
    case CIRCUIT = 'circuit';
    case PERSONNELLE = 'personnelle';

    public function label(): string
    {
        return match ($this) {
            self::CIRCUIT => 'Visible par le circuit',
            self::PERSONNELLE => 'Personnelle',
        };
    }
}
