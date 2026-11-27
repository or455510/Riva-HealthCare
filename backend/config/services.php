<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/api/auth/google/callback'),
    ],

    'frontend' => [
        'url' => env('FRONTEND_URL', 'http://localhost:4200'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'verify_ssl' => env('GEMINI_VERIFY_SSL', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_WHATSAPP_FROM'),
    ],

    // Phase 0: previously hardcoded as private constants directly in
    // EmergencyAlertController (the project owner's own personal email/phone).
    // Kept as the default here only for behavioral continuity with the existing
    // deployment; every real deployment should set these explicitly per environment,
    // and this becomes a genuinely per-clinic setting once multi-tenancy (Phase 1) lands.
    'emergency' => [
        'admin_email' => env('EMERGENCY_ADMIN_EMAIL', 'or455510@gmail.com'),
        'admin_whatsapp' => env('EMERGENCY_ADMIN_WHATSAPP', '+201012077945'),
    ],
];
