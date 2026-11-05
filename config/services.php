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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // WagHub WhatsApp Gateway, the same keys as gais-web, dnd-web and helpdesk-web.
    'whatsapp_gateway' => [
        // An empty WAG_URL= line still falls back to WagHub.
        'url' => env('WAG_URL') ?: 'https://waghub.mekayastudio.com',
        // WAG_API_KEY is the name older OnTime servers still use.
        'token' => env('WAG_TOKEN') ?: env('WAG_API_KEY'),
        'connect_timeout' => (float) env('WA_CONNECT_TIMEOUT', 5),
        'timeout' => (float) env('WA_API_TIMEOUT', 15),
    ],

    'whatsapp_otp' => [
        'ttl_minutes' => (int) env('WHATSAPP_OTP_TTL_MINUTES', 5),
    ],

    'email_login' => [
        'expires_in' => (int) env('EMAIL_LOGIN_EXPIRES_IN', 600),
    ],

    // Where the email login link sends the mobile app. Expo Go in development needs its own URL,
    // e.g. exp://127.0.0.1:8081/--/
    'mobile_app' => [
        'deep_link' => env('MOBILE_APP_DEEP_LINK', 'ontime://'),
    ],

    // Expo Push API for the mobile app. EXPO_ACCESS_TOKEN is only needed once "enhanced push
    // security" is enabled for the Expo project.
    'expo' => [
        'push_url' => env('EXPO_PUSH_URL') ?: 'https://exp.host/--/api/v2/push/send',
        'access_token' => env('EXPO_ACCESS_TOKEN'),
        'connect_timeout' => (float) env('EXPO_CONNECT_TIMEOUT', 5),
        'timeout' => (float) env('EXPO_API_TIMEOUT', 15),
    ],

    'google' => [
        'maps_key' => env('GOOGLE_MAPS_API_KEY', ''),
    ],

    'core' => [
        'client_id' => env('CORE_CLIENT_ID'),
        'client_secret' => env('CORE_CLIENT_SECRET'),
        'redirect' => env('CORE_REDIRECT_URI'),
        'webhook_secret' => env('CORE_WEBHOOK_SECRET'),
    ],

    // Face matching and liveness for attendance selfies.
    'face' => [
        // basic: built-in pixel comparison, not real face recognition. compreface: self-hosted CompreFace.
        'driver' => env('FACE_DRIVER', 'basic'),
        'compreface' => [
            'url' => env('COMPREFACE_URL'), // e.g. http://compreface:8000
            'verification_key' => env('COMPREFACE_VERIFICATION_KEY'),
            'detection_key' => env('COMPREFACE_DETECTION_KEY'),
            'timeout' => (int) env('COMPREFACE_TIMEOUT', 15),
            'det_prob_threshold' => (float) env('COMPREFACE_DET_PROB_THRESHOLD', 0.8),
        ],
        'liveness' => [
            'ttl_seconds' => (int) env('LIVENESS_TTL_SECONDS', 180),
            'front_max_yaw' => 15, // degrees, |yaw| allowed for the "hadap depan" frame
            'side_min_yaw' => 20, // degrees, |yaw| needed for each "tengok" frame
            'max_pitch' => 25, // degrees, |pitch| allowed on every frame
        ],
    ],

];
