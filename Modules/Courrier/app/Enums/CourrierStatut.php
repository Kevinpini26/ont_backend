<?php

namespace Modules\Courrier\Enums;

/**
 * Circuit courrier de l'ONT. Depuis la correction du Lot 1 (bouclage), ce
 * n'est plus un ordre strict à sens unique : la table de transitions
 * (config('courrier.circuit_transitions')) autorise plusieurs destinations
 * possibles depuis un même statut, chacune gardée par une condition et un
 * poste habilité — voir ConfigCircuitTransitionRules.
 */
enum CourrierStatut: string
{
    case RECU = 'recu';
    case AU_PROTOCOLE = 'au_protocole';

    /**
     * Historique uniquement — plus jamais atteint par le circuit standard
     * (voir config('courrier.circuit_transitions.complet') : au_protocole
     * transmet désormais directement à en_attente_tri). Conservé dans
     * l'enum pour ne pas casser l'affichage de transitions déjà
     * enregistrées avant cette correction. La DGA n'intervient plus que via
     * une garde d'intérim dynamique sur en_attente_avis_dg, voir
     * CourrierCircuitService::rendreAvisDg().
     */
    case EN_CIRCUIT_HIERARCHIQUE = 'en_circuit_hierarchique';

    /**
     * Tri par degré d'urgence, Secrétariat 01 — toujours entre la Réception
     * (ou le Protocole, si applicable) et la Direction Générale. Ajouté au
     * Lot 1 (bouclage) ; la porte métier réelle (urgence obligatoire avant
     * de sortir de ce statut, bannettes dédiées) reste à construire au Lot 2.
     */
    case EN_ATTENTE_TRI = 'en_attente_tri';
    case EN_ATTENTE_AVIS_DG = 'en_attente_avis_dg';

    /**
     * Point de passage du bouclage interne (Lot 1) : un avis DG "réservé"
     * renvoie le dossier ici plutôt qu'en projet_reponse_en_cours — la
     * Réception le représente ensuite à la DG, tour suivant. Décision prise
     * au Lot 3 : ce point de passage reste la Réception, y compris pour un
     * courrier déjà imputé — seul un avis favorable sur un courrier imputé
     * part vers EN_DISPATCH (voir plus bas), jamais un avis réservé.
     */
    case RETOUR_RECEPTION = 'retour_reception';
    case PROJET_REPONSE_EN_COURS = 'projet_reponse_en_cours';

    /**
     * Lot 3 (orientation et dispatch) : un avis DG favorable rendu sur un
     * courrier déjà imputé (voir Courrier::imputations) part ici plutôt
     * qu'en PROJET_REPONSE_EN_COURS — l'imputation route le dossier vers une
     * direction plutôt que vers une rédaction interne de réponse. Le
     * Secrétariat 02 transmet ensuite au secrétariat de la direction
     * imputée à titre principal.
     */
    case EN_DISPATCH = 'en_dispatch';

    /**
     * Le dossier est arrivé au secrétariat de la direction imputée à titre
     * principal (UserRole::SECRETARIAT_DIRECTION), qui le met à disposition
     * du responsable de direction et des agents — terminal pour ce lot,
     * la suite (tableau de répartition, retour vers la DG) est le Lot 4.
     */
    case CHEZ_DIRECTION = 'chez_direction';

    /**
     * Lot 4 (tableau de répartition) : statuts d'un courrier "synthétique"
     * (Courrier::type = TABLEAU_REPARTITION, voir TableauRepartition::courrier())
     * qui porte le trajet Réception -> DG du tableau lui-même, sans jamais
     * apparaître dans les files d'attente ordinaires — voir
     * TableauRepartitionCircuitService, un service dédié qui n'utilise ni
     * config('courrier.circuit_transitions') ni le bordereau/décharge du
     * courrier ordinaire (décision : simple trace, pas de blocage — un
     * tableau n'est jamais remis "de la main à la main"). TABLEAU_CHEZ_RECEPTION
     * sert à la fois de point d'entrée (la DFP soumet) et de point de
     * retour (la DG renvoie avec observations, tour+1) — même rôle que
     * RETOUR_RECEPTION/representer_dg pour le courrier ordinaire.
     */
    case TABLEAU_CHEZ_RECEPTION = 'tableau_chez_reception';
    case TABLEAU_EN_ATTENTE_AVIS_DG = 'tableau_en_attente_avis_dg';
    case TABLEAU_APPROUVE = 'tableau_approuve';

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
            self::EN_ATTENTE_TRI => 'En attente de tri',
            self::RETOUR_RECEPTION => 'Retour à la Réception',
            self::EN_ATTENTE_AVIS_DG => "En attente d'avis DG",
            self::PROJET_REPONSE_EN_COURS => 'Projet de réponse en cours',
            self::EN_DISPATCH => 'En dispatch vers la direction',
            self::CHEZ_DIRECTION => 'Chez le secrétariat de la direction',
            self::TABLEAU_CHEZ_RECEPTION => 'Chez la Réception (tableau)',
            self::TABLEAU_EN_ATTENTE_AVIS_DG => "En attente d'avis DG (tableau)",
            self::TABLEAU_APPROUVE => 'Tableau approuvé',
            self::EN_ATTENTE_VALIDATION_DG => 'En attente de validation DG',
            self::EN_RELECTURE => 'En relecture',
            self::SIGNE => 'Signé',
            self::ENREGISTRE => 'Enregistré',
            self::ENVOYE => 'Envoyé',
        };
    }
}
