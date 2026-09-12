<?php

namespace Modules\Stagiaires\Enums;

/**
 * Issue proposée par la DFP pour une ligne de tableau (Lot A) — devient
 * l'issue réelle du stagiaire à l'approbation (Lot B, voir
 * StagiaireStatut::RETENU/NON_RETENU).
 */
enum IssueProposee: string
{
    case RETENU = 'retenu';
    case NON_RETENU = 'non_retenu';

    public function label(): string
    {
        return match ($this) {
            self::RETENU => 'Retenu',
            self::NON_RETENU => 'Non retenu',
        };
    }
}
