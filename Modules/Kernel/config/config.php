<?php

use Modules\Kernel\Enums\Poste;

return [
    'name' => 'Kernel',

    /**
     * Postes du rôle agent_circuit_courrier qui contournent le filtrage
     * par direction (DirectionScope) car ils traitent les dossiers de
     * toutes les directions dans le circuit courrier central.
     */
    'circuit_courrier_central_postes' => [
        Poste::RECEPTION->value,
        Poste::PROTOCOLE->value,
        Poste::DGA->value,
        Poste::ASSISTANT_PROTOCOLE->value,
        Poste::ASSISTANT_1->value,
        Poste::ASSISTANT_2->value,
        Poste::ASSISTANT_DGA->value,
        Poste::DG->value,
        Poste::SECRETARIAT_1->value,
        Poste::SECRETARIAT_2->value,
    ],

    /**
     * Mention d'information due à la personne concernée au plus tard lors
     * de la collecte de ses données à caractère personnel — article 220 de
     * l'ordonnance-loi n°23/010 du 13 mars 2023 portant Code du numérique
     * (RDC). Reprend le contenu exigé point par point ; les valeurs
     * marquées "à confirmer" dépendent d'un usage propre à l'ONT non
     * tranché dans le code — voir docs/conformite-donnees.md et
     * docs/questions-ont.md.
     */
    'mention_information' => [
        'responsable_traitement' => 'Office National du Tourisme (ONT) — Direction de la Formation et de la Professionnalisation, pour les dossiers de stage ; Protocole/circuit courrier, pour la correspondance.',
        'finalites' => [
            'Instruction et suivi des demandes de stage.',
            'Traitement et suivi de la correspondance adressée à l\'ONT.',
        ],
        'destinataires' => "Les agents de l'ONT habilités selon leur fonction (DFP, direction d'accueil concernée, circuit courrier central) — voir les politiques d'accès du système.",
        'duree_conservation' => "À confirmer avec la DFP/le Secrétariat Général (voir docs/conformite-donnees.md) — l'ordonnance-loi n'impose pas de durée fixe, seulement de ne pas excéder ce qui est nécessaire aux finalités poursuivies (article 193, 3°).",
        'droits' => "Droit d'accès, de rectification et d'opposition, à exercer auprès du responsable du traitement ci-dessus, ainsi que droit de réclamation auprès de l'Autorité de protection des données à caractère personnel compétente.",
        'transfert_etat_tiers' => "Aucun transfert de données vers un État tiers n'est effectué dans le cadre de ces traitements.",
        'caractere_obligatoire' => "Les informations demandées sont nécessaires à l'instruction du dossier ; un refus de les communiquer empêche son traitement.",
    ],
];
