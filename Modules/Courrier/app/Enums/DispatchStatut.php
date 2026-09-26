<?php

namespace Modules\Courrier\Enums;

enum DispatchStatut: string
{
    case EN_ATTENTE = 'en_attente';
    case EXECUTE = 'execute';
    case ANNULE = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::EXECUTE => 'Exécuté',
            self::ANNULE => 'Annulé',
        };
    }
}
