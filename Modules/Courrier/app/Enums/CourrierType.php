<?php

namespace Modules\Courrier\Enums;

enum CourrierType: string
{
    case DEMANDE_STAGE = 'demande_stage';
    case CORRESPONDANCE_GENERALE = 'correspondance_generale';

    /**
     * Lot 4 : courrier "synthétique" créé par TableauRepartitionCircuitService,
     * jamais par CourrierCircuitService::creer() — porte le trajet
     * Réception -> DG d'un tableau de répartition, pas une correspondance.
     */
    case TABLEAU_REPARTITION = 'tableau_repartition';

    public function label(): string
    {
        return match ($this) {
            self::DEMANDE_STAGE => 'Demande de stage',
            self::CORRESPONDANCE_GENERALE => 'Correspondance générale',
            self::TABLEAU_REPARTITION => 'Tableau de répartition',
        };
    }
}
