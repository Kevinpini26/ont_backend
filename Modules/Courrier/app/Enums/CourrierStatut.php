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
    case BROUILLON_DIRECTION = 'brouillon_direction';

    /**
     * Historique uniquement : le Protocole n'existe plus dans
     * l'organisation actuelle. Conservé pour hydrater et afficher les
     * anciens dossiers sans réécrire leurs transitions.
     */
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
     * Depuis l'étape assistants/classeur (voir EN_ATTENTE_CLASSEUR
     * ci-dessous), ne mène plus systématiquement ici : seul un degré urgent
     * ou très urgent y conduit désormais (voir
     * CourrierCircuitService::transmettreEnAttenteAvisDg()).
     */
    case EN_ATTENTE_TRI = 'en_attente_tri';
    case EN_ATTENTE_AVIS_DG = 'en_attente_avis_dg';

    /**
     * Le classeur d'attente du Secrétariat 01 — distinct de RETOUR_RECEPTION
     * ci-dessous (voir le contraste dans son docblock) : un dossier trié ici
     * n'a jamais quitté le bureau, alors que RETOUR_RECEPTION revient d'un
     * tour complet chez la DG. Atteint depuis en_attente_tri quand le degré
     * d'urgence retenu est "normal" ; en reparts vers en_attente_avis_dg
     * quand le Secrétariat 01 choisit de le transmettre (voir
     * CourrierCircuitService::transmettreDepuisClasseur()).
     */
    case EN_ATTENTE_CLASSEUR = 'en_attente_classeur';

    /**
     * Point de passage du bouclage interne (Lot 1) : un avis DG "réservé"
     * renvoie le dossier ici plutôt qu'en projet_a_rediger — la
     * Réception le représente ensuite à la DG, tour suivant. Décision prise
     * au Lot 3 : ce point de passage reste la Réception, y compris pour un
     * courrier déjà imputé — seul un avis favorable sur un courrier imputé
     * part vers EN_DISPATCH (voir plus bas), jamais un avis réservé.
     * Distinct d'EN_ATTENTE_CLASSEUR ci-dessus : celui-ci revient d'un tour
     * complet (la DG a déjà examiné le dossier), celui-là n'est jamais parti.
     */
    case RETOUR_RECEPTION = 'retour_reception';

    /**
     * Rédaction du projet de réponse — poste des assistants
     * (ASSISTANT_1/ASSISTANT_2/ASSISTANT_DGA), jamais le
     * Secrétariat 01 (qui garde le tri et l'établissement des accusés de
     * réception, voir config('courrier.circuit_transitions.complet')).
     */
    case PROJET_A_REDIGER = 'projet_a_rediger';

    /**
     * Projet soumis par l'assistant rédacteur à un relecteur désigné —
     * distinct d'EN_RELECTURE (partagé par les circuits dg_initie/sortant,
     * jamais atteint par le circuit complet) : même mécanique de relecteur
     * désigné (voir Courrier::enAttenteValidationRelecteur()), mais un nom
     * propre au circuit complet, qui seul connaît le renvoi pour correction
     * (voir CourrierCircuitService::renvoyerPourCorrection()) vers
     * PROJET_A_REDIGER.
     */
    case PROJET_A_VALIDER = 'projet_a_valider';
    /** Validation DG achevée, document imprimable mais signature physique absente. */
    case EN_ATTENTE_SIGNATURE = 'en_attente_signature';

    /**
     * Lot 3 (orientation et dispatch) : un avis DG favorable rendu sur un
     * courrier déjà imputé (voir Courrier::imputations) part ici plutôt
     * qu'en PROJET_A_REDIGER — l'imputation route le dossier vers une
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
    case DISPATCH_EXECUTE = 'dispatch_execute';

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
    case DISPONIBLE_RETRAIT = 'disponible_retrait';
    case REMIS = 'remis';
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
            self::BROUILLON_DIRECTION => 'Brouillon produit par une direction',
            self::AU_PROTOCOLE => 'Au protocole (historique)',
            self::EN_CIRCUIT_HIERARCHIQUE => 'En circuit hiérarchique',
            self::EN_ATTENTE_TRI => 'En attente de tri',
            self::EN_ATTENTE_CLASSEUR => 'Au classeur d\'attente',
            self::RETOUR_RECEPTION => 'Retour à la Réception',
            self::EN_ATTENTE_AVIS_DG => "En attente d'avis DG",
            self::PROJET_A_REDIGER => 'Projet de réponse à rédiger',
            self::PROJET_A_VALIDER => 'Projet en attente de validation',
            self::EN_ATTENTE_SIGNATURE => 'En attente de signature',
            self::EN_DISPATCH => 'En dispatch vers la direction',
            self::CHEZ_DIRECTION => 'Chez le secrétariat de la direction',
            self::DISPATCH_EXECUTE => 'Dispatch exécuté',
            self::TABLEAU_CHEZ_RECEPTION => 'Chez la Réception (tableau)',
            self::TABLEAU_EN_ATTENTE_AVIS_DG => "En attente d'avis DG (tableau)",
            self::TABLEAU_APPROUVE => 'Tableau approuvé',
            self::EN_ATTENTE_VALIDATION_DG => 'En attente de validation DG',
            self::EN_RELECTURE => 'En relecture',
            self::SIGNE => 'Signé',
            self::DISPONIBLE_RETRAIT => 'Disponible pour retrait',
            self::REMIS => 'Remis',
            self::ENREGISTRE => 'Enregistré',
            self::ENVOYE => 'Envoyé',
        };
    }
}
