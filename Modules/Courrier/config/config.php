<?php

use Modules\Kernel\Enums\Poste;

return [
    'name' => 'Courrier',

    /**
     * Le circuit complet est le seul circuit actif pour les nouvelles
     * entrées. La branche `court` reste uniquement lisible pour l'historique.
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
     *     - recu -> en_attente_tri : la Réception crée le dossier et remet
     *       son bordereau au Secrétariat 01. Après décharge, le Secrétariat
     *       01 place le dossier dans sa file de tri. L'ancien Protocole ne
     *       fait plus partie de l'organisation ; le statut au_protocole est
     *       conservé uniquement pour relire les historiques existants.
     *     - en_attente_tri -> en_attente_avis_dg : le Secrétariat 01 trie
     *       par degré d'urgence puis transmet à la DG — condition
     *       'urgence_signalee' (urgent ou très urgent), vérifiée AVANT le
     *       repli vers en_attente_classeur ci-dessous.
     *     - en_attente_tri -> en_attente_classeur : sans condition (repli),
     *       pour un degré d'urgence normal — le dossier n'a jamais quitté le
     *       bureau du Secrétariat 01, qui le transmettra lui-même à la DG
     *       quand il le jugera bon (voir
     *       CourrierCircuitService::transmettreDepuisClasseur()). Distinct de
     *       retour_reception, qui revient d'un tour déjà instruit par la DG.
     *     - en_attente_classeur -> en_attente_avis_dg : le Secrétariat 01
     *       transmet depuis le classeur, à son initiative.
     *     - en_attente_avis_dg -> en_dispatch : condition
     *       'avis_dg_favorable_impute' (Lot 3), vérifiée AVANT
     *       'avis_dg_tranche' ci-dessous puisque les deux conditions
     *       peuvent être vraies en même temps sur un avis favorable — un
     *       avis favorable sur un courrier déjà imputé (voir
     *       Courrier::imputations, direction principale) part vers le
     *       dispatch plutôt que vers une rédaction interne de réponse.
     *     - en_attente_avis_dg -> projet_a_rediger : condition
     *       'avis_dg_tranche' (favorable sans imputation, ou défavorable).
     *       La DG (ou la DGA, uniquement lorsque la DG est marquée
     *       indisponible — garde métier dynamique dans
     *       CourrierCircuitService::rendreAvisDg(), pas dans cette table
     *       statique) transmet à l'assistant pour rédaction du projet de
     *       réponse (voir docs/questions-ont.md).
     *     - en_attente_avis_dg -> retour_reception : condition
     *       'avis_dg_reserve'. Un avis "réservé" veut dire que la décision
     *       n'est pas arrêtée : le dossier boucle plutôt que d'avancer
     *       comme si une décision avait été prise — y compris sur un
     *       courrier déjà imputé (Lot 3 : seul un avis favorable exploite
     *       l'imputation, jamais un avis réservé).
     *     - retour_reception -> en_attente_tri : la Réception remet le
     *       dossier à SEC1. Cette transition incrémente le tour ; SEC1 doit
     *       ensuite retrier avant toute nouvelle présentation à la DG.
     *     - en_dispatch -> chez_direction : le Secrétariat 02 transmet le
     *       courrier imputé au secrétariat de la direction imputée à titre
     *       principal (Lot 3). Étape terminale pour ce lot — la suite
     *       (tableau de répartition, retour vers la DG) est le Lot 4.
     *     - projet_a_rediger -> projet_a_valider : un assistant DG actif
     *       (Ass1 ou Ass2) soumet son projet
     *       de réponse à un relecteur désigné. Le Secrétariat 01 ne rédige
     *       plus : il garde le tri et l'établissement des accusés de
     *       réception (voir docs/questions-ont.md).
     *     - projet_a_valider -> projet_a_rediger : renvoi pour correction,
     *       observation obligatoire — hors de cette table (comme
     *       validerRelecture()), gardé par le relecteur désigné lui-même,
     *       voir CourrierCircuitService::renvoyerPourCorrection().
     *     - projet_a_valider -> signe : la DG signe — refusé si le relecteur
     *       désigné n'a pas explicitement validé la relecture.
     *     - signe -> enregistre : le Secrétariat 02 enregistre le courrier
     *       signé (numérotation, classification interne/externe).
     *
     * - 'court' : conservé exclusivement pour relire les dossiers
     *   historiques. Aucune transition active ne permet désormais de créer
     *   ou de poursuivre ce contournement du circuit central.
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
                    'action' => 'transmettre_tri',
                    'statut_arrivee' => 'en_attente_tri',
                    'postes' => [Poste::SECRETARIAT_1->value],
                    'condition' => null,
                ],
            ],
            'en_attente_tri' => [
                [
                    'action' => 'transmettre_dg',
                    'statut_arrivee' => 'en_attente_avis_dg',
                    'postes' => [Poste::SECRETARIAT_1->value],
                    'condition' => 'urgence_signalee',
                ],
                [
                    'action' => 'transmettre_dg',
                    'statut_arrivee' => 'en_attente_classeur',
                    'postes' => [Poste::SECRETARIAT_1->value],
                    'condition' => null,
                ],
            ],
            'en_attente_classeur' => [
                [
                    'action' => 'transmettre_depuis_classeur',
                    'statut_arrivee' => 'en_attente_avis_dg',
                    'postes' => [Poste::SECRETARIAT_1->value],
                    'condition' => null,
                ],
            ],
            'en_attente_avis_dg' => [
                [
                    'action' => 'rendre_avis_favorable_impute',
                    'statut_arrivee' => 'en_dispatch',
                    'postes' => [Poste::DG->value, Poste::DGA->value],
                    'condition' => 'avis_dg_favorable_impute',
                ],
                [
                    'action' => 'rendre_avis_tranche',
                    'statut_arrivee' => 'projet_a_rediger',
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
                    'action' => 'transmettre_sec1',
                    'statut_arrivee' => 'en_attente_tri',
                    'postes' => [Poste::RECEPTION->value],
                    'condition' => null,
                ],
            ],
            'en_dispatch' => [
                [
                    'action' => 'dispatcher_direction',
                    'statut_arrivee' => 'chez_direction',
                    'postes' => [Poste::SECRETARIAT_2->value],
                    'condition' => null,
                ],
            ],
            'chez_direction' => [],
            'projet_a_rediger' => [
                [
                    'action' => 'soumettre_projet_reponse',
                    'statut_arrivee' => 'projet_a_valider',
                    'postes' => [
                        Poste::ASSISTANT_1->value,
                        Poste::ASSISTANT_2->value,
                    ],
                    'condition' => null,
                ],
            ],
            'projet_a_valider' => [
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
            'recu' => [],
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
            'projet_a_rediger' => [],
            'projet_a_valider' => [
                [
                    'action' => 'signer',
                    'statut_arrivee' => 'signe',
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
                    'action' => 'envoyer',
                    'statut_arrivee' => 'envoye',
                    'postes' => [Poste::SECRETARIAT_2->value],
                    'condition' => null,
                ],
            ],
            'envoye' => [],
        ],
    ],

    'circuit' => [
        /**
         * Au-delà de ce nombre de tours (Courrier::tour), un dossier encore
         * dans la boucle est signalé en alerte sur le tableau de bord de la
         * DG (voir CourrierStatistiqueController::dg) plutôt que de tourner
         * indéfiniment sans que personne ne le voie.
         */
        'tours_avant_alerte' => 5,
    ],

    'reponse_externe' => [
        // Durée de validité du lien signé envoyé au demandeur externe.
        // Le PDF reste privé et n'est jamais exposé par son chemin de stockage.
        'lien_ttl_minutes' => config('ont.courrier_reponse_externe.lien_ttl_minutes', 10080),
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
        'projet_a_rediger' => 72,
        'projet_a_valider' => 24,
    ],
];
