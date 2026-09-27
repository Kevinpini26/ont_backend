<?php

namespace Modules\Courrier\Enums;

enum MissionDocumentaireType: string
{
    case GENERALE = 'generale';
    case PREPARATION_REPONSE = 'preparation_reponse';

    public function label(): string
    {
        return match ($this) {
            self::GENERALE => 'Mission générale',
            self::PREPARATION_REPONSE => 'Préparation de réponse',
        };
    }
}
