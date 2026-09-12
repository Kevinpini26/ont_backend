<?php

namespace Modules\Stagiaires\Enums;

enum StagiaireStatut: string
{
    case DOSSIER_RECU = 'dossier_recu';
    case EN_ATTENTE_AFFECTATION = 'en_attente_affectation';

    /**
     * Lot 5 (verrouiller la diffusion) : le dossier a été proposé dans un
     * tableau de répartition (voir TableauRepartitionLigne) — une
     * proposition, pas encore une affectation réelle. Rien ne sort tant
     * que le tableau qui le porte n'est pas approuvé par la DG (voir
     * TableauRepartitionCircuitService::rendreAvis()) : ni notification,
     * ni convention, ni date d'arrivée communiquée. Retiré du tableau
     * (TableauRepartitionCircuitService::retirerLigne()) : retour à
     * EN_ATTENTE_AFFECTATION.
     */
    case EN_INSTRUCTION = 'en_instruction';
    case AFFECTE = 'affecte';
    case STAGE_EN_COURS = 'stage_en_cours';
    case EVALUATION_EN_COURS = 'evaluation_en_cours';
    case CLOTURE = 'cloture';

    /**
     * Lot B (issue individuelle) : issue symétrique de AFFECTE — "retenu"
     * n'est pas un statut à part, c'est ce que veut dire AFFECTE (la
     * proposition devient réelle, le cycle de vie continue normalement) ;
     * seul le refus avait besoin d'un statut propre, terminal, qui n'existait
     * pas. Sort de la boucle comme un dossier retenu : classé (voir
     * Courrier::cote_classement posé à l'approbation, Lot D), ne réapparaît
     * plus dans aucune file de traitement.
     */
    case NON_RETENU = 'non_retenu';

    public function label(): string
    {
        return match ($this) {
            self::DOSSIER_RECU => 'Dossier reçu',
            self::EN_ATTENTE_AFFECTATION => "En attente d'affectation",
            self::EN_INSTRUCTION => 'En instruction (tableau de répartition)',
            self::AFFECTE => 'Affecté',
            self::STAGE_EN_COURS => 'Stage en cours',
            self::EVALUATION_EN_COURS => 'Évaluation en cours',
            self::CLOTURE => 'Clôturé',
            self::NON_RETENU => 'Non retenu',
        };
    }
}
