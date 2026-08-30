<?php

namespace Modules\Courrier\Enums;

enum NiveauConfidentialite: string
{
    case ORDINAIRE = 'ordinaire';
    case CONFIDENTIEL = 'confidentiel';
    case SECRET = 'secret';

    public function label(): string
    {
        return match ($this) {
            self::ORDINAIRE => 'Ordinaire',
            self::CONFIDENTIEL => 'Confidentiel',
            self::SECRET => 'Secret',
        };
    }
}
