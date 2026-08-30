<?php

use Modules\Kernel\Enums\Poste;

return [
    'name' => 'Courrier',

    /**
     * Deux circuits possibles pour un courrier, selon qu'il exige ou non un
     * arbitrage de la DG (Courrier::necessite_avis_dg, déterminé
     * automatiquement à la création — voir CourrierCircuitService::creer).
     * Pour chaque statut courant, le statut suivant autorisé et le(s)
     * poste(s) habilité(s) à déclencher cette transition précise. Toute
     * transition qui ne figure pas ici (saut d'étape, ordre inversé, ou
     * circuit inapplicable) est refusée.
     *
     * - 'complet' : mail externe entrant (toujours), demande de stage
     *   (toujours), ou courrier à destination de la DG elle-même.
     *     - recu -> au_protocole : le Protocole prend en charge le courrier
     *       reçu.
     *     - au_protocole -> en_attente_avis_dg : le Protocole transmet
     *       directement à la DG pour avis — la DGA n'est PAS une étape
     *       obligatoire du circuit standard (corrigé : un établissement
     *       public transmet directement à la DG, la DGA n'intervenant qu'en
     *       intérim, voir ci-dessous). Le statut historique
     *       "en_circuit_hierarchique" (CourrierStatut::EN_CIRCUIT_HIERARCHIQUE)
     *       n'est plus jamais atteint par le circuit standard — conservé
     *       dans l'enum uniquement pour l'affichage d'un historique déjà
     *       existant.
     *     - en_attente_avis_dg -> projet_reponse_en_cours : la DG (ou la
     *       DGA, uniquement lorsque la DG est marquée indisponible — garde
     *       métier dynamique dans CourrierCircuitService::rendreAvisDg(),
     *       pas dans cette table statique) rend l'avis
     *       (favorable/défavorable/réservé) et transmet au Secrétariat 01.
     *       Le poste DGA figure ci-dessous parmi les postes structurellement
     *       habilités pour cette étape ; la garde d'intérim en restreint
     *       l'usage réel.
     *     - projet_reponse_en_cours -> en_relecture : le Secrétariat 01
     *       soumet son projet de réponse à un relecteur désigné.
     *     - en_relecture -> signe : la DG signe — refusé si le relecteur
     *       désigné n'a pas explicitement validé la relecture.
     *     - signe -> enregistre : le Secrétariat 02 enregistre le courrier
     *       signé (numérotation, classification interne/externe).
     *
     * - 'court' : courrier initié directement par une direction à
     *   destination d'une autre direction (jamais vers la DG, jamais une
     *   demande de stage) — aucun besoin d'arbitrage DG.
     *     - recu -> enregistre : le Secrétariat 02 enregistre directement
     *       le courrier (numérotation, traçabilité), sans passer par le
     *       Protocole, la DGA, ni attendre d'avis DG. Il arrive ensuite tel
     *       quel dans l'espace de la direction destinataire.
     *
     * - 'dg_initie' : courrier sortant initié par la DG elle-même
     *   (instruction, note de service), sans courrier entrant déclencheur
     *   — voir CourrierCircuitService::initierParDg(). Ni Protocole ni avis
     *   DG (il part déjà de la DG), mais relecture et signature restent
     *   obligatoires comme pour tout courrier officiel. Pas d'entrée
     *   "recu" : la création bascule directement vers en_attente_validation_dg
     *   ou en_relecture selon la case "validation_dg_requise" du
     *   formulaire — une décision prise une fois, en code, à la création,
     *   jamais une transition pilotée par un poste via cette table.
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
                'suivant' => 'au_protocole',
                'postes' => [Poste::PROTOCOLE->value],
            ],
            'au_protocole' => [
                'suivant' => 'en_attente_avis_dg',
                'postes' => [Poste::PROTOCOLE->value],
            ],
            'en_attente_avis_dg' => [
                'suivant' => 'projet_reponse_en_cours',
                'postes' => [Poste::DG->value, Poste::DGA->value],
            ],
            'projet_reponse_en_cours' => [
                'suivant' => 'en_relecture',
                'postes' => [Poste::SECRETARIAT_1->value],
            ],
            'en_relecture' => [
                'suivant' => 'signe',
                'postes' => [Poste::DG->value],
            ],
            'signe' => [
                'suivant' => 'enregistre',
                'postes' => [Poste::SECRETARIAT_2->value],
            ],
            'enregistre' => [
                'suivant' => null,
                'postes' => [],
            ],
        ],
        'court' => [
            'recu' => [
                'suivant' => 'enregistre',
                'postes' => [Poste::SECRETARIAT_2->value],
            ],
            'enregistre' => [
                'suivant' => null,
                'postes' => [],
            ],
        ],
        'dg_initie' => [
            'en_attente_validation_dg' => [
                'suivant' => 'en_relecture',
                'postes' => [Poste::DG->value],
            ],
            'en_relecture' => [
                'suivant' => 'signe',
                'postes' => [Poste::DG->value],
            ],
            'signe' => [
                'suivant' => 'enregistre',
                'postes' => [Poste::SECRETARIAT_2->value],
            ],
            'enregistre' => [
                'suivant' => null,
                'postes' => [],
            ],
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
                'suivant' => 'signe',
                'postes' => [Poste::DG->value],
            ],
            'signe' => [
                'suivant' => 'envoye',
                'postes' => [Poste::SECRETARIAT_2->value],
            ],
            'envoye' => [
                'suivant' => null,
                'postes' => [],
            ],
        ],
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
];
