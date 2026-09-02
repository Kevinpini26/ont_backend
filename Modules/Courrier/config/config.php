<?php

use Modules\Kernel\Enums\Poste;

return [
    'name' => 'Courrier',

    /**
     * Deux circuits possibles pour un courrier, selon qu'il exige ou non un
     * arbitrage de la DG (Courrier::necessite_avis_dg, déterminé
     * automatiquement à la création — voir CourrierCircuitService::creer).
     * Chaque statut courant porte une LISTE de transitions candidates (pas
     * un unique "suivant" scalaire) : la première dont la condition est
     * satisfaite (voir ConfigCircuitTransitionRules::conditionSatisfaite())
     * l'emporte ; `condition: null` signifie "toujours satisfaite", et sert
     * de repli par défaut en dernière position. C'est ce qui permet à un
     * même statut de mener à plusieurs suites — la condition première pour
     * qu'une boucle existe (voir 'retour_reception' ci-dessous). Toute
     * transition dont aucune entrée ne correspond (saut d'étape, ordre
     * inversé, ou circuit inapplicable) est refusée.
     *
     * - 'complet' : mail externe entrant (toujours), demande de stage
     *   (toujours), ou courrier à destination de la DG elle-même.
     *     - recu -> au_protocole : condition 'protocole_requis', jamais
     *       satisfaite aujourd'hui (voir 'categories_protocole' plus bas,
     *       vide) — le Protocole n'est pas supprimé du circuit (il figure
     *       dans le document de flux officiel de l'ONT), mais aucune
     *       catégorie de courrier confirmée n'en a besoin pour l'instant.
     *       Voir docs/questions-ont.md.
     *     - recu -> en_attente_tri : sans condition, le comportement par
     *       défaut — la Réception transmet directement au tri du
     *       Secrétariat 01, sans Protocole, conformément au circuit décrit
     *       par la Direction.
     *     - au_protocole -> en_attente_tri : si jamais le Protocole est un
     *       jour emprunté, il transmet lui aussi au tri, jamais directement
     *       à la DG — le tri précède toujours la DG, qu'il y ait eu
     *       Protocole ou non.
     *     - en_attente_tri -> en_attente_avis_dg : le Secrétariat 01 trie
     *       par degré d'urgence puis transmet à la DG. La porte métier
     *       réelle (urgence obligatoire, bannettes) est ajoutée au Lot 2 ;
     *       ce statut n'est pour l'instant qu'un point de passage.
     *     - en_attente_avis_dg -> projet_reponse_en_cours : condition
     *       'avis_dg_tranche' (favorable ou défavorable). La DG (ou la DGA,
     *       uniquement lorsque la DG est marquée indisponible — garde
     *       métier dynamique dans CourrierCircuitService::rendreAvisDg(),
     *       pas dans cette table statique) transmet au Secrétariat 01 pour
     *       rédaction du projet de réponse.
     *     - en_attente_avis_dg -> retour_reception : condition
     *       'avis_dg_reserve'. Un avis "réservé" veut dire que la décision
     *       n'est pas arrêtée : le dossier boucle plutôt que d'avancer
     *       comme si une décision avait été prise. Destination provisoire
     *       (voir CourrierStatut::RETOUR_RECEPTION) : en Lot 3, une fois
     *       l'imputation câblée, la direction imputée remplacera la
     *       Réception comme destinataire réel de ce complément.
     *     - retour_reception -> en_attente_avis_dg : la Réception représente
     *       le dossier à la DG — c'est cette transition, et elle seule, qui
     *       incrémente le tour de boucle (voir
     *       CourrierCircuitService::representerDg()).
     *     - projet_reponse_en_cours -> en_relecture : le Secrétariat 01
     *       soumet son projet de réponse à un relecteur désigné — le second
     *       rôle du Secrétariat 01, distinct du tri (statut distinct,
     *       jamais confondus dans une seule étape).
     *     - en_relecture -> signe : la DG signe — refusé si le relecteur
     *       désigné n'a pas explicitement validé la relecture.
     *     - signe -> enregistre : le Secrétariat 02 enregistre le courrier
     *       signé (numérotation, classification interne/externe).
     *
     * - 'court' : courrier initié directement par une direction à
     *   destination d'une autre direction (jamais vers la DG, jamais une
     *   demande de stage) — aucun besoin d'arbitrage DG, donc aucun tri par
     *   le Secrétariat 01 (réservé au circuit 'complet').
     *     - recu -> enregistre : le Secrétariat 02 enregistre directement
     *       le courrier (numérotation, traçabilité), sans passer par le
     *       Protocole, la DGA, ni attendre d'avis DG. Il arrive ensuite tel
     *       quel dans l'espace de la direction destinataire.
     *
     * - 'dg_initie' : courrier sortant initié par la DG elle-même
     *   (instruction, note de service), sans courrier entrant déclencheur
     *   — voir CourrierCircuitService::initierParDg(). Ni Protocole ni avis
     *   DG (il part déjà de la DG), pas de tri non plus (n'est pas du
     *   courrier entrant), mais relecture et signature restent obligatoires
     *   comme pour tout courrier officiel. Pas d'entrée "recu" : la création
     *   bascule directement vers en_attente_validation_dg ou en_relecture
     *   selon la case "validation_dg_requise" du formulaire — une décision
     *   prise une fois, en code, à la création, jamais une transition
     *   pilotée par un poste via cette table.
     *     - en_attente_validation_dg -> en_relecture : la DG valide le
     *       contenu avant qu'il ne parte en relecture (uniquement si le
     *       rédacteur a jugé cette étape nécessaire).
     *     - en_relecture -> signe : la DG signe (même garde que le circuit
     *       complet : refusé si le relecteur désigné n'a pas validé).
     *     - signe -> enregistre : le Secrétariat 02 enregistre, comme les
     *       deux autres circuits.
     */
    'circuit_transitions' => [
        'complet' => [
            'recu' => [
                [
                    'action' => 'transmettre_protocole',
                    'statut_arrivee' => 'au_protocole',
                    'postes' => [Poste::PROTOCOLE->value],
                    'condition' => 'protocole_requis',
                ],
                [
                    'action' => 'transmettre_tri',
                    'statut_arrivee' => 'en_attente_tri',
                    'postes' => [Poste::SECRETARIAT_1->value],
                    'condition' => null,
                ],
            ],
            'au_protocole' => [
                [
                    'action' => 'transmettre_au_tri',
                    'statut_arrivee' => 'en_attente_tri',
                    'postes' => [Poste::PROTOCOLE->value],
                    'condition' => null,
                ],
            ],
            'en_attente_tri' => [
                [
                    'action' => 'transmettre_dg',
                    'statut_arrivee' => 'en_attente_avis_dg',
                    'postes' => [Poste::SECRETARIAT_1->value],
                    'condition' => null,
                ],
            ],
            'en_attente_avis_dg' => [
                [
                    'action' => 'rendre_avis_tranche',
                    'statut_arrivee' => 'projet_reponse_en_cours',
                    'postes' => [Poste::DG->value, Poste::DGA->value],
                    'condition' => 'avis_dg_tranche',
                ],
                [
                    'action' => 'rendre_avis_reserve',
                    'statut_arrivee' => 'retour_reception',
                    'postes' => [Poste::DG->value, Poste::DGA->value],
                    'condition' => 'avis_dg_reserve',
                ],
            ],
            'retour_reception' => [
                [
                    'action' => 'representer_dg',
                    'statut_arrivee' => 'en_attente_avis_dg',
                    'postes' => [Poste::RECEPTION->value],
                    'condition' => null,
                ],
            ],
            'projet_reponse_en_cours' => [
                [
                    'action' => 'soumettre_projet_reponse',
                    'statut_arrivee' => 'en_relecture',
                    'postes' => [Poste::SECRETARIAT_1->value],
                    'condition' => null,
                ],
            ],
            'en_relecture' => [
                [
                    'action' => 'signer',
                    'statut_arrivee' => 'signe',
                    'postes' => [Poste::DG->value],
                    'condition' => null,
                ],
            ],
            'signe' => [
                [
                    'action' => 'enregistrer',
                    'statut_arrivee' => 'enregistre',
                    'postes' => [Poste::SECRETARIAT_2->value],
                    'condition' => null,
                ],
            ],
            'enregistre' => [],
        ],
        'court' => [
            'recu' => [
                [
                    'action' => 'enregistrer',
                    'statut_arrivee' => 'enregistre',
                    'postes' => [Poste::SECRETARIAT_2->value],
                    'condition' => null,
                ],
            ],
            'enregistre' => [],
        ],
        'dg_initie' => [
            'en_attente_validation_dg' => [
                [
                    'action' => 'valider_avant_diffusion',
                    'statut_arrivee' => 'en_relecture',
                    'postes' => [Poste::DG->value],
                    'condition' => null,
                ],
            ],
            'en_relecture' => [
                [
                    'action' => 'signer',
                    'statut_arrivee' => 'signe',
                    'postes' => [Poste::DG->value],
                    'condition' => null,
                ],
            ],
            'signe' => [
                [
                    'action' => 'enregistrer',
                    'statut_arrivee' => 'enregistre',
                    'postes' => [Poste::SECRETARIAT_2->value],
                    'condition' => null,
                ],
            ],
            'enregistre' => [],
        ],

        /**
         * 'sortant' : courrier de réponse à un courrier d'arrivée
         * (Courrier::sens = sortant, voir CourrierCircuitService::initierReponseSortante()) —
         * créé directement à en_relecture (le relecteur est désigné à la
         * création, comme soumettreProjetReponse() le fait déjà pour la
         * réponse historique). La DG signe, puis le Secrétariat 02 marque
         * l'envoi effectif (numéro de départ, date d'envoi) — "envoye"
         * remplace "enregistre" comme état terminal, un courrier sortant
         * n'est jamais "enregistré" au sens du registre arrivée.
         */
        'sortant' => [
            'en_relecture' => [
                [
                    'action' => 'signer',
                    'statut_arrivee' => 'signe',
                    'postes' => [Poste::DG->value],
                    'condition' => null,
                ],
            ],
            'signe' => [
                [
                    'action' => 'envoyer',
                    'statut_arrivee' => 'envoye',
                    'postes' => [Poste::SECRETARIAT_2->value],
                    'condition' => null,
                ],
            ],
            'envoye' => [],
        ],
    ],

    /**
     * Catégories de courrier (Courrier::type) pour lesquelles le passage par
     * le Protocole est requis avant le tri — vide aujourd'hui : aucune
     * catégorie confirmée. Voir docs/questions-ont.md. Activer un jour ne
     * demande qu'une entrée ici (et, si besoin, un nouveau cas
     * CourrierType), jamais une nouvelle migration ni un nouveau statut.
     */
    'categories_protocole' => [],

    'circuit' => [
        /**
         * Au-delà de ce nombre de tours (Courrier::tour), un dossier encore
         * dans la boucle est signalé en alerte sur le tableau de bord de la
         * DG (voir CourrierStatistiqueController::dg) plutôt que de tourner
         * indéfiniment sans que personne ne le voie.
         */
        'tours_avant_alerte' => 5,
    ],

    /**
     * Lot 2 (tri par urgence) : un dossier encore "en_attente_tri" sans
     * degre_urgence renseigné au-delà de ce délai remonte en alerte plutôt
     * que de rester invisible — jamais un blocage du service (le dossier
     * reste consultable normalement pendant ce temps). Simplification déjà
     * adoptée ailleurs dans ce module (voir 'delais_indicatifs_heures'
     * ci-dessous) : heures d'horloge, pas un calcul d'heures ouvrées réel
     * — à ajuster si la pratique de terrain l'exige. "4 heures ouvrées"
     * demandé par la Direction ; 4h d'horloge en repli tant qu'un
     * calendrier d'heures ouvrées n'existe pas dans ce projet.
     */
    'tri' => [
        'delai_alerte_heures' => 4,
    ],

    /**
     * Poste habilité à créer un courrier entrant (statut initial "recu").
     */
    'poste_creation' => Poste::RECEPTION->value,

    /**
     * Format du numéro de départ (registre sortant) — provisoire, voir
     * docs/questions-ont.md : le format exact reste à valider avec le
     * Secrétariat Général. %d = année, %04d = séquence annuelle.
     */
    'format_numero_depart' => '%d-D%04d',

    /**
     * Règle de cotation du classement physique — provisoire, voir
     * docs/questions-ont.md. Jetons disponibles : {direction} (code de la
     * direction imputée, ou "ONT" si aucune), {annee}, {sequence}
     * (numéro d'enregistrement seul, sans l'année).
     */
    'format_cote_classement' => '{direction}-{annee}-{sequence}',

    /**
     * Délai indicatif (en heures) avant qu'un courrier soit considéré "en
     * souffrance" à son étape courante — voir Modules\Courrier\Support\CourrierEnSouffrance.
     * Valeur par défaut appliquée à tout statut sans entrée spécifique
     * ci-dessous. Ces durées sont un choix de présentation raisonnable,
     * pas une norme métier confirmée — voir docs/questions-ont.md.
     */
    'delai_indicatif_heures_par_defaut' => 48,
    'delais_indicatifs_heures' => [
        'en_attente_avis_dg' => 48,
        'en_relecture' => 24,
        'projet_reponse_en_cours' => 72,
    ],
];
