<?php

namespace Modules\Courrier\Enums;

enum DecisionDirection: string
{
    case TRAITEMENT_TERMINE = 'traitement_termine';
    case RETOUR_A_PREPARER = 'retour_a_preparer';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::TRAITEMENT_TERMINE => 'Traitement terminé',
            self::RETOUR_A_PREPARER => 'Retour à préparer',
            self::AUTRE => 'Autre décision directionnelle',
        };
    }
}
