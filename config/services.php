<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Nexolu Communications
    |--------------------------------------------------------------------------
    | Unico canal de WhatsApp y correo. No hay driver alterno a proposito:
    | este producto nace del lado correcto en vez de repetir el paso
    | intermedio por Meta directo que todavia tiene el POS.
    */
    'comms_core' => [
        'api_key' => env('COMMS_CORE_API_KEY'),
        'base_url' => env('COMMS_CORE_BASE_URL', 'http://localhost:8010'),
        'webhook_secret' => env('COMMS_CORE_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Nexolu IA Core
    |--------------------------------------------------------------------------
    | Credencial simetrica: el IA Core la usa para llamar
    | POST /api/ai/tools/invoke de esta API, y esta API la usa para llamar
    | POST {base_url}/v1/chat.
    */
    'ia_core' => [
        'api_key' => env('IA_CORE_API_KEY'),
        'base_url' => env('IA_CORE_BASE_URL', 'http://localhost:8000'),
        'app_id' => env('IA_CORE_APP_ID', 'spa'),
    ],

    'payments_core' => [
        'api_key' => env('PAYMENTS_CORE_API_KEY'),
        'base_url' => env('PAYMENTS_CORE_BASE_URL', 'http://localhost:8020'),
    ],

];
