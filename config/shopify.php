<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Shopify Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Shopify API integration
    |
    */

    'domain' => env('SHOPIFY_DOMAIN', ''),
    'api_token' => env('SHOPIFY_API_TOKEN', ''),
    'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET', ''),
    'api_version' => env('SHOPIFY_API_VERSION', '2025-07'),
];
