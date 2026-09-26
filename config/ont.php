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
];
