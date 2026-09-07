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

    /*
    |--------------------------------------------------------------------------
    | Nexolu Auth
    |--------------------------------------------------------------------------
    | Identidad centralizada. A diferencia de los otros tres, aca NO hay una
    | `base_url`: esta API nunca llama a nexolu-auth. La llave publica va
    | fijada y la verificacion es local, para que el login de este producto
    | no dependa de que otro servicio este arriba.
    |
    | `public_keys` vacio = el canje responde 503 y POST /v1/login sigue
    | funcionando igual. Ese es el interruptor para apagar el SSO sin
    | apagar el login propio.
    |
    | Formato: {"<kid>": "<PEM publico en base64>"}. Acepta varios kids a la
    | vez, que es lo que permite rotar la llave sin downtime.
    */
    'nexolu_auth' => [
        'issuer' => env('NEXOLU_AUTH_ISSUER', 'https://auth.nexolu.co'),
        'audience' => env('NEXOLU_AUTH_AUDIENCE', 'nexolu-spa-api'),
        'public_keys' => env('NEXOLU_AUTH_PUBLIC_KEYS', '{}'),
        // Seguro contra "restaure un dump y cambiaron los ids". Se apaga
        // cuando el mapeo de linked_accounts este probado.
        'email_fallback' => env('NEXOLU_AUTH_EMAIL_FALLBACK', true),
    ],

];
