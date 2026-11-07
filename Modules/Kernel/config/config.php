<?php

use Modules\Kernel\Enums\Poste;

return [
    'name' => 'Kernel',

    /**
     * Mot de passe initial du compte administrateur créé hors local/testing
     * (AdminUserSeeder). Vide : mot de passe aléatoire inutilisable.
     */
    'admin_initial_password' => env('KERNEL_ADMIN_INITIAL_PASSWORD'),

    /** Appels API par minute et par utilisateur connecté (les anonymes restent à 60 par IP). */
    'limite_api_connecte' => (int) env('API_LIMITE_CONNECTE', 240),

    /**
     * Proxys de confiance (voir ConfigurerProxysDeConfiance) : adresses ou plages CIDR séparées
     * par des virgules, ou « * ». Vide par défaut : aucun en-tête X-Forwarded-* n'est cru.
     */
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /**
     * Postes du rôle agent_circuit_courrier qui contournent le filtrage
     * par direction (DirectionScope) car ils traitent les dossiers de
     * toutes les directions dans le circuit courrier central.
     */
    'circuit_courrier_central_postes' => [
        Poste::RECEPTION->value,
        Poste::DGA->value,
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
        'responsable_traitement' => 'Office National du Tourisme (ONT) — Direction de la Formation et de la Professionnalisation, pour les dossiers de stage ; service du courrier, pour la correspondance.',
        'finalites' => [
            'Instruction et suivi des demandes de stage.',
            'Traitement et suivi de la correspondance adressée à l\'ONT.',
        ],
        'destinataires' => "Les agents de l'ONT habilités selon leur fonction (DFP, direction d'accueil concernée, circuit courrier central) — voir les politiques d'accès du système.",
        'duree_conservation' => 'Les données sont conservées pendant la durée nécessaire aux finalités poursuivies, puis supprimées ou anonymisées.',
        'droits' => "Droit d'accès, de rectification et d'opposition, à exercer auprès du responsable du traitement ci-dessus, ainsi que droit de réclamation auprès de l'Autorité de protection des données à caractère personnel compétente.",
        'transfert_etat_tiers' => "Aucun transfert de données vers un État tiers n'est effectué dans le cadre de ces traitements.",
        'caractere_obligatoire' => "Les informations demandées sont nécessaires à l'instruction du dossier ; un refus de les communiquer empêche son traitement.",
    ],

    /**
     * Passerelle SMS — voir Modules\Kernel\Support\SmsNotificationCanal.
     * Aucun fournisseur n'est choisi par l'ONT à ce jour : 'log' (défaut)
     * consigne sans envoyer réellement. Passer à 'http' et renseigner les
     * clés ci-dessous une fois un fournisseur retenu (voir docs/questions-ont.md).
     */
    'sms' => [
        'driver' => config('ont.sms.driver', 'log'),
        'http_url' => config('ont.sms.http_url'),
        'http_champ_destinataire' => config('ont.sms.http_champ_destinataire', 'to'),
        'http_champ_message' => config('ont.sms.http_champ_message', 'message'),
        'http_params_supplementaires' => [],
    ],

    /**
     * Numérisation du courrier physique — voir docs/numerisation-courrier.md.
     * Plafonds en kilooctets (comme les règles de validation Laravel
     * 'max:'), distincts des plafonds historiques de StoreCourrierRequest
     * (5 Mo, pour un fichier déjà prêt déposé directement) : un scan
     * multi-pages pèse structurellement plus lourd.
     */
    'numerisation' => [
        'duree_jeton_capture_minutes' => 15,
        'plafond_ko_courrier' => 15360,
        'plafond_ko_stagiaire' => 20480,
        // En-deçà, le fichier trahit probablement une image illisible
        // (compression excessive, page blanche) — refusé plutôt que
        // silencieusement accepté en mauvaise qualité.
        'seuil_octets_par_page' => 20 * 1024,
    ],

    /**
     * Reconnaissance de caractères (OCR) sur les documents numérisés qui ne
     * contiennent aucun texte embarqué (scan image pur) — voir
     * Modules\Kernel\Support\ExtracteurTexteDocumentNumerise et
     * docs/numerisation-courrier.md. Désactivée par défaut : exige les
     * binaires système `pdftoppm` (paquet poppler-utils) et `tesseract` +
     * `tesseract-ocr-fra` (paquets tesseract-ocr, tesseract-ocr-fra),
     * absents de l'image Docker actuelle — à ajouter explicitement avant
     * d'activer OCR_ACTIVE en production. Le système reste pleinement
     * utilisable sans (recherche limitée au texte déjà embarqué dans les
     * PDF, à l'objet et aux métadonnées).
     */
    'ocr' => [
        'active' => config('ont.ocr.active', false),
    ],

    /*
     * Calendrier de travail (lot 6) : valeurs par défaut, toutes réglables dans « Paramètres de
     * l'organisation » (clés calendrier.*). Horaires et jours fériés PROVISOIRES, à confirmer par l'ONT :
     * voir docs/questions-ont.md. « MM-JJ » revient chaque année, « AAAA-MM-JJ » vaut une seule fois.
     */
    'calendrier' => [
        'jours_ouvres' => [1, 2, 3, 4, 5],
        'heure_debut' => '08:30',
        'heure_fin' => '15:30',
        'fuseau' => 'Africa/Kinshasa',
        'jours_feries' => [
            ['date' => '01-01', 'libelle' => 'Nouvel An'],
            ['date' => '01-04', 'libelle' => "Martyrs de l'indépendance"],
            ['date' => '01-16', 'libelle' => 'Héros national Laurent-Désiré Kabila'],
            ['date' => '01-17', 'libelle' => 'Héros national Patrice Emery Lumumba'],
            ['date' => '04-06', 'libelle' => 'Simon Kimbangu et conscience africaine'],
            ['date' => '05-01', 'libelle' => 'Fête du travail'],
            ['date' => '05-17', 'libelle' => 'Révolution et libération'],
            ['date' => '06-30', 'libelle' => "Fête de l'indépendance"],
            ['date' => '08-01', 'libelle' => 'Fête des parents'],
            ['date' => '12-25', 'libelle' => 'Noël'],
        ],
    ],
];
