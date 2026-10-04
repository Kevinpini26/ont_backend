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
    ],

    'courrier_scan_signe_taille_max_ko' => (int) env('COURRIER_SCAN_SIGNE_TAILLE_MAX_KO', 10240),
];
