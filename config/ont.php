<?php

return [
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'http_url' => env('SMS_HTTP_URL'),
        'http_champ_destinataire' => env('SMS_HTTP_CHAMP_DESTINATAIRE', 'to'),
        'http_champ_message' => env('SMS_HTTP_CHAMP_MESSAGE', 'message'),
    ],

    'ocr' => [
        'active' => env('OCR_ACTIVE', false),
    ],

    'courrier_reponse_externe' => [
        'lien_ttl_minutes' => (int) env('COURRIER_REPONSE_LIEN_TTL_MINUTES', 10080),
        'piece_jointe_limite_octets' => (int) env('COURRIER_REPONSE_PIECE_JOINTE_LIMITE_OCTETS', 10 * 1024 * 1024),
    ],

    'courrier_scan_signe_taille_max_ko' => (int) env('COURRIER_SCAN_SIGNE_TAILLE_MAX_KO', 10240),

    /**
     * Coordonnées officielles affichées dans le pied des emails sortants
     * (voir ReponseFinaleCourrierExterneMail et le futur gabarit commun,
     * Phase 1.5). Valeurs d'exemple ci-dessous, à remplacer par les
     * coordonnées réelles de l'ONT avant tout déploiement — voir
     * docs/questions-ont.md.
     */
    'contact' => [
        'email' => env('ONT_CONTACT_EMAIL', 'contact@exemple-ont.cd'),
        'telephone' => env('ONT_CONTACT_TELEPHONE', '+243 000 000 000'),
        'adresse' => env('ONT_CONTACT_ADRESSE', 'Kinshasa, République Démocratique du Congo'),
    ],
];
