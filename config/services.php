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

    /*
    |--------------------------------------------------------------------------
    | ML Prediction Microservice (Python FastAPI)
    |--------------------------------------------------------------------------
    */
    'ml_service' => [
        'url' => env('ML_SERVICE_URL', 'http://localhost:8001'),
        'api_key' => env('ML_SERVICE_API_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | SEC EDGAR API
    |--------------------------------------------------------------------------
    |
    | The SEC requires a descriptive User-Agent header that includes an email
    | address per their Fair Access policy (https://www.sec.gov/privacy).
    | Company facts files can be large (2-8 MB for mature companies), so a
    | generous timeout is necessary.
    |
    */
    'sec_edgar' => [
        'base_url' => env('SEC_EDGAR_BASE_URL', 'https://data.sec.gov'),
        'user_agent' => env('SEC_EDGAR_USER_AGENT', 'StockPredictionApp/1.0 (your-email@example.com)'),
        'timeout' => (int) env('SEC_EDGAR_TIMEOUT', 120),
        'connect_timeout' => (int) env('SEC_EDGAR_CONNECT_TIMEOUT', 15),
    ],

];
