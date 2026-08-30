<?php

namespace Modules\Courrier\Enums;

/**
 * Mention d'imputation d'un courrier vers une direction — vocabulaire
 * donné tel quel par le métier (pas une invention de ce chantier). Voir
 * docs/questions-ont.md : à confirmer que ces cinq mentions couvrent bien
 * l'usage réel de la DG.
 */
enum MentionImputation: string
{
    case POUR_ATTRIBUTION = 'pour_attribution';
    case POUR_AVIS = 'pour_avis';
    case POUR_SUITE_UTILE = 'pour_suite_utile';
    case POUR_INFORMATION = 'pour_information';
    case POUR_CLASSEMENT = 'pour_classement';

    public function label(): string
    {
        return match ($this) {
            self::POUR_ATTRIBUTION => 'Pour attribution',
            self::POUR_AVIS => 'Pour avis',
            self::POUR_SUITE_UTILE => 'Pour suite utile',
            self::POUR_INFORMATION => 'Pour information',
            self::POUR_CLASSEMENT => 'Pour classement',
        };
    }
}
