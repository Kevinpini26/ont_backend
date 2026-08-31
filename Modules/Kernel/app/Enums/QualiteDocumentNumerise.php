<?php

namespace Modules\Kernel\Enums;

/**
 * Indicateur de qualité calculé à l'ingestion (poids par page trop faible,
 * nombre de pages incohérent avec ce qu'annonçait l'agent...) — voir le
 * service d'ingestion (lot 1/3). Un indicateur, pas un blocage : un
 * document "faible" reste accepté et consultable, juste signalé.
 */
enum QualiteDocumentNumerise: string
{
    case BONNE = 'bonne';
    case FAIBLE = 'faible';

    public function label(): string
    {
        return match ($this) {
            self::BONNE => 'Bonne qualité',
            self::FAIBLE => 'Qualité faible — à vérifier',
        };
    }
}
