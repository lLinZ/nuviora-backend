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
    'facebook' => [
        'pixel_id' => env('FACEBOOK_PIXEL_ID'),
        'access_token' => env('FACEBOOK_ACCESS_TOKEN'),
    ],

    // Integración de Meta Ads (Módulo 2 de Fran). Los tokens no van aquí: cada conexión guarda el suyo, cifrado, en
    // meta_connections (§3, §4). La versión se cambia en un solo lugar (§53); sin versión, la Ads API responde 2635.
    'meta' => [
        'graph_version' => env('META_GRAPH_API_VERSION', 'v26.0'),
        // §8: días de la importación inicial; §25: tamaño de cada pedido del histórico
        'initial_days' => 30,
        'history_chunk_days' => 7,
        // §57: cada cuánto se piden los rangos fijos (alcance y frecuencia de Meta). Se ajusta con la tarea 0 (§52).
        'presets_every_minutes' => (int) env('META_PRESETS_EVERY_MINUTES', 60),
        // §52: se frena antes del límite de Meta, al llegar a este % de uso
        'usage_stop_percent' => 75,
        // Segundos de trabajo por cada parte de la sincronización (la cola relanza lo que pasa de 90 s)
        'job_budget_seconds' => 35,
        // §15: el evento principal es Purchase; Meta lo informa con estos nombres (se usa el primero que venga)
        'purchase_actions' => ['omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase'],
        // §32: "name" = el código completo del anuncio; "prefix" = solo "C004". Pendiente de Fran (pregunta 2).
        'tracking_id_mode' => env('META_TRACKING_ID_MODE', 'name'),
    ],
    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'whatsapp' => [
        'access_token'    => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'waba_id'         => env('WHATSAPP_WABA_ID'),
        'verify_token'    => env('WHATSAPP_VERIFY_TOKEN'),
    ],

    // Revisión de comprobantes de pago con IA (Fran, 2026-10-03). Modo: off (no revisa), observe (revisa y
    // muestra el resultado sin bloquear) o enforce (además no deja entregar si el comprobante no cuadra).
    'openai' => [
        'key'           => env('OPENAI_API_KEY'),
        'receipt_model' => env('OPENAI_RECEIPT_MODEL', 'gpt-6-luna'),
        // Respaldo para los comprobantes que el principal no lee con seguridad (Fran, 2026-10-09). Vacío lo apaga.
        'receipt_fallback_model' => env('OPENAI_RECEIPT_FALLBACK_MODEL', 'gpt-6-sol'),
    ],
    'receipt_checks' => [
        'mode' => env('RECEIPT_CHECKS_MODE', 'off'),
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

];
