<?php

namespace Modules\Courrier\Enums;

enum ModeExpedition: string
{
    case PORTEUR = 'porteur';
    case POSTE = 'poste';
    case COURRIEL = 'courriel';

    public function label(): string
    {
        return match ($this) {
            self::PORTEUR => 'Porteur',
            self::POSTE => 'Poste',
            self::COURRIEL => 'Courriel',
        };
    }
}
