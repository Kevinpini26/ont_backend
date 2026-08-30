<?php

namespace Modules\Courrier\Enums;

enum ModeRemise: string
{
    case PORTEUR_AVEC_DECHARGE = 'porteur_avec_decharge';
    case POSTE = 'poste';
    case COURRIEL = 'courriel';

    public function label(): string
    {
        return match ($this) {
            self::PORTEUR_AVEC_DECHARGE => 'Porteur avec décharge signée',
            self::POSTE => 'Poste',
            self::COURRIEL => 'Courriel',
        };
    }
}
