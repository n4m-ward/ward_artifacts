<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Primeiro admin
    |--------------------------------------------------------------------------
    |
    | O cadastro é só por convite, então o primeiro usuário nasce pelo
    | AdminUserSeeder a partir destes valores (php artisan db:seed).
    |
    */

    'admin' => [
        'name' => env('WARD_ADMIN_NAME', 'Admin'),
        'username' => env('WARD_ADMIN_USERNAME', 'admin'),
        'email' => env('WARD_ADMIN_EMAIL'),
        'password' => env('WARD_ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Usernames reservados
    |--------------------------------------------------------------------------
    |
    | O username é o primeiro segmento da URL dos artifacts, então não pode
    | colidir com rotas do app.
    |
    */

    'reserved_usernames' => [
        'admin', 'api', 'build', 'c', 'confirm-password', 'connections', 'dashboard',
        'forgot-password', 'invite', 'login', 'logout', 'mcp', 'oauth', 'register',
        'reset-password', 'settings', 'storage', 'up', 'users', 'verify-email', 'well-known',
    ],

    /*
    |--------------------------------------------------------------------------
    | Artifacts
    |--------------------------------------------------------------------------
    */

    'artifacts' => [
        'disk' => env('WARD_ARTIFACT_DISK', 'artifacts'),

        // Limite total por envio (HTML único ou zip já descompactado).
        'max_bytes' => (int) env('WARD_ARTIFACT_MAX_BYTES', 100 * 1024 * 1024),

        // Limites de um bundle (.zip com index.html + assets).
        'max_files' => 2000,
        'bundle_extensions' => [
            'html', 'htm', 'css', 'js', 'mjs', 'json', 'map', 'txt', 'md', 'csv', 'tsv', 'xml',
            'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp',
            'woff', 'woff2', 'ttf', 'otf',
            'mp3', 'wav', 'ogg', 'oga', 'm4a', 'flac', 'mp4', 'webm', 'ogv', 'mov',
            'glb', 'gltf', 'bin', 'wasm', 'pdf',
        ],

        // Versões antigas guardadas por artifact (a atual sempre fica).
        'keep_versions' => 10,

        // Validade do link de upload devolvido pelo MCP.
        'upload_ttl_minutes' => 15,

        // Durações permitidas para deixar um artifact público (em horas).
        'public_durations' => [1, 2, 6, 12, 24, 48, 168],

        // Origem que serve o HTML bruto. Vazio = mesma origem do app.
        'content_url' => env('WARD_CONTENT_URL'),
    ],

];
