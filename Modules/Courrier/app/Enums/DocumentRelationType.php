<?php

namespace Modules\Courrier\Enums;

enum DocumentRelationType: string
{
    case REPONSE_A = 'reponse_a';
    case SUITE_DE = 'suite_de';
    case PRODUIT_A_PARTIR_DE = 'produit_a_partir_de';
    case DOCUMENT_RETOUR = 'document_retour';
    case REMPLACE = 'remplace';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::REPONSE_A => 'Réponse à',
            self::SUITE_DE => 'Suite de',
            self::PRODUIT_A_PARTIR_DE => 'Produit à partir de',
            self::DOCUMENT_RETOUR => 'Document de retour',
            self::REMPLACE => 'Remplace',
            self::AUTRE => 'Autre relation',
        };
    }
}
