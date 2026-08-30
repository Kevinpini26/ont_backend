<?php

namespace Modules\Courrier\Enums;

/**
 * Circuit courrier de l'ONT, dans l'ordre strict imposé.
 */
enum CourrierStatut: string
{
    case RECU = 'recu';
    case AU_PROTOCOLE = 'au_protocole';

    /**
     * Historique uniquement — plus jamais atteint par le circuit standard
     * (voir config('courrier.circuit_transitions.complet') : au_protocole
     * transmet désormais directement à en_attente_avis_dg). Conservé dans
     * l'enum pour ne pas casser l'affichage de transitions déjà
     * enregistrées avant cette correction. La DGA n'intervient plus que via
     * une garde d'intérim dynamique sur en_attente_avis_dg, voir
     * CourrierCircuitService::rendreAvisDg().
     */
    case EN_CIRCUIT_HIERARCHIQUE = 'en_circuit_hierarchique';
    case EN_ATTENTE_AVIS_DG = 'en_attente_avis_dg';
    case PROJET_REPONSE_EN_COURS = 'projet_reponse_en_cours';

    /**
     * Circuit "dg_initie" uniquement (voir config('courrier.circuit_transitions'))
     * : un courrier initié par la DG dont le rédacteur a coché « Nécessite
     * la validation de la DG avant envoi ». Précède en_relecture ; jamais
     * atteint par les circuits complet/court.
     */
    case EN_ATTENTE_VALIDATION_DG = 'en_attente_validation_dg';
    case EN_RELECTURE = 'en_relecture';
    case SIGNE = 'signe';
    case ENREGISTRE = 'enregistre';

    /**
     * État terminal d'un courrier sortant (Courrier::sens = sortant, voir
     * config('courrier.circuit_transitions.sortant')) — jamais atteint par
     * un courrier entrant, qui se termine à ENREGISTRE.
     */
    case ENVOYE = 'envoye';

    public function label(): string
    {
        return match ($this) {
            self::RECU => 'Reçu',
            self::AU_PROTOCOLE => 'Au protocole',
            self::EN_CIRCUIT_HIERARCHIQUE => 'En circuit hiérarchique',
            self::EN_ATTENTE_AVIS_DG => "En attente d'avis DG",
            self::PROJET_REPONSE_EN_COURS => 'Projet de réponse en cours',
            self::EN_ATTENTE_VALIDATION_DG => 'En attente de validation DG',
            self::EN_RELECTURE => 'En relecture',
            self::SIGNE => 'Signé',
            self::ENREGISTRE => 'Enregistré',
            self::ENVOYE => 'Envoyé',
        };
    }

    /**
     * Ordre strict du circuit ; sert de filet de sécurité par défaut pour
     * interdire tout saut d'étape.
     *
     * @return CourrierStatut[]
     */
    public static function ordre(): array
    {
        return self::cases();
    }
}
