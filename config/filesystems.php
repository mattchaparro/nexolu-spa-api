<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | DigitalOcean Spaces
        |----------------------------------------------------------------------
        | Compatible con S3, asi que usa el mismo driver cambiando el endpoint.
        |
        | Las imagenes de servicios y de trabajos NO van al disco del droplet:
        | ese servidor es de 2023, su imagen base ya no existe en DigitalOcean,
        | y no hay backups automatizados en ningun droplet del ecosistema. Una
        | recreacion se llevaria por delante todas las fotos.
        |
        | En local cae a `public` (disco del proyecto) si SPACES_KEY esta vacio,
        | para no exigir credenciales de nube solo para desarrollar.
        */
        'spaces' => [
            'driver' => 's3',
            'key' => env('SPACES_KEY'),
            'secret' => env('SPACES_SECRET'),
            'region' => env('SPACES_REGION', 'nyc3'),
            'bucket' => env('SPACES_BUCKET'),
            'endpoint' => env('SPACES_ENDPOINT'),
            // La URL publica va por el CDN de Spaces, no por el endpoint de
            // la API: sirve las imagenes desde el borde y no consume ancho de
            // banda del droplet.
            'url' => env('SPACES_CDN_URL'),
            'use_path_style_endpoint' => false,
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
