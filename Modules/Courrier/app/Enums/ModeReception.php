<?php

namespace Modules\Courrier\Enums;

enum ModeReception: string
{
    case PORTEUR = 'porteur';
    case POSTE = 'poste';
    case COURRIEL = 'courriel';
    case DEPOT_EN_LIGNE = 'depot_en_ligne';

    public function label(): string
    {
        return match ($this) {
            self::PORTEUR => 'Porteur',
            self::POSTE => 'Poste',
            self::COURRIEL => 'Courriel',
            self::DEPOT_EN_LIGNE => 'Dépôt en ligne',
        };
    }
}
