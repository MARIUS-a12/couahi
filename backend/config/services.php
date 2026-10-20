<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Configuré n'est pas disponible : un établissement peut avoir saisi ses
    // identifiants CinetPay, le paiement en ligne n'est proposé au client que
    // si l'intégration elle-même est active sur la plateforme (voir
    // Etablissement::cinetpayEstDisponible). L'intégration n'est pas encore
    // construite : false par défaut. Le jour où elle l'est, on passe
    // CINETPAY_ACTIF à true, et rien d'autre ne change.
    'cinetpay' => [
        'actif' => (bool) env('CINETPAY_ACTIF', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
