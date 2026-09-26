<?php

namespace Modules\Courrier\Enums;

enum TraitementDirectionStatut: string
{
    case RECU_SECRETARIAT = 'recu_secretariat';
    case TRANSMIS_DIRECTEUR = 'transmis_directeur';
    case EN_TRAITEMENT = 'en_traitement';
    case TERMINE_DIRECTEUR = 'termine_directeur';

    public function label(): string
    {
        return match ($this) {
            self::RECU_SECRETARIAT => 'Reçu par le secrétariat',
            self::TRANSMIS_DIRECTEUR => 'Transmis au Directeur',
            self::EN_TRAITEMENT => 'En traitement par le Directeur',
            self::TERMINE_DIRECTEUR => 'Traitement terminé par le Directeur',
        };
    }
}
