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
    | Ward Design (canvas de artboards)
    |--------------------------------------------------------------------------
    */

    'designs' => [
        'max_boards' => 200,
        'max_board_bytes' => 5 * 1024 * 1024,
        'max_bytes' => 100 * 1024 * 1024,
        'keep_snapshots' => 10,
        // Espaço entre artboards posicionados automaticamente.
        'gap' => 80,
        // Onde os artboards podem ser embutidos além do próprio app: o MCP App
        // roda dentro do Claude (claude.ai e o app desktop), num domínio
        // *.claudemcpcontent.com. Vai no frame-ancestors do conteúdo.
        'embed_ancestors' => array_filter(explode(' ', (string) env(
            'WARD_DESIGN_EMBED_ANCESTORS',
            'https://claude.ai https://*.claude.ai https://*.claudemcpcontent.com',
        ))),
        // Por quanto tempo get_design conta o que a pessoa está olhando no app.
        'viewing_ttl_minutes' => 30,

        // Prints para a IA conferir o que desenhou (get_artboard_image,
        // get_design_image): um Chrome headless no servidor abre o artboard ou
        // o canvas. Sem binário configurado, as ferramentas avisam e a IA usa
        // o navegador dela. Na Hostinger: chrome-headless-shell do Chrome for
        // Testing + as bibliotecas que faltam numa pasta (library_path).
        'screenshots' => [
            'chrome' => env('WARD_SCREENSHOT_CHROME'),
            'library_path' => env('WARD_SCREENSHOT_LIBRARY_PATH'),
            'timeout' => 45,
            // Tempo virtual que a página tem para carregar fontes, fotos e JS.
            'budget_ms' => 8000,
            // Tamanho máximo do print de um artboard (px, já com a escala).
            'board_max_width' => 1200,
            'board_max_height' => 3600,
            // Janela do print do canvas inteiro.
            'design_width' => 1600,
            'design_max_height' => 2000,
            'jpeg_quality' => 82,
        ],
    ],

    // Fuso usado para mostrar datas no painel (o banco guarda em UTC).
    'display_timezone' => env('WARD_DISPLAY_TIMEZONE', 'America/Sao_Paulo'),

    // Liga o ward:post-deploy (migrations automáticas a cada commit novo).
    // Só em produção: localmente o scheduler não deve migrar sozinho.
    'post_deploy' => (bool) env('WARD_POST_DEPLOY', false),

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
        'admin', 'api', 'artifacts', 'build', 'c', 'confirm-password', 'connections', 'd', 'dashboard',
        'forgot-password', 'invite', 'login', 'logout', 'mcp', 'oauth', 'p', 'register',
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

        // Link de pré-visualização de uso único devolvido pelo MCP (/p/{token}).
        'preview_ttl_minutes' => (int) env('WARD_PREVIEW_TTL_MINUTES', 60),

        // Depois do primeiro acesso, o mesmo navegador pode recarregar (F5) por
        // este tempo; outros navegadores recebem 403.
        'preview_session_minutes' => (int) env('WARD_PREVIEW_SESSION_MINUTES', 720),

        // Validade do conteúdo carregado pela pré-visualização (o iframe e os
        // assets dele): curta, porque esse link não exige login.
        'preview_content_ttl_minutes' => 15,

        // Maior HTML aceito direto no argumento de uma ferramenta MCP. Acima
        // disso a IA recebe um link de upload.
        'mcp_inline_max_bytes' => 2 * 1024 * 1024,

        // Maior HTML devolvido inline por get_artifact_source.
        'mcp_source_inline_max_bytes' => 256 * 1024,

        // Durações permitidas para deixar um artifact público (em horas).
        'public_durations' => [1, 2, 6, 12, 24, 48, 168],

        // Origem separada que serve o HTML bruto (ex.: https://content.wardtecnology.com).
        // Com ela o artifact roda com allow-same-origin e tem localStorage/IndexedDB
        // sem alcançar a sessão do app. Vazio = mesma origem do app, num sandbox
        // totalmente fechado (sem storage).
        'content_url' => env('WARD_CONTENT_URL'),

        // Validade do token que libera o conteúdo para a moldura do viewer.
        'content_ttl_minutes' => (int) env('WARD_CONTENT_TTL_MINUTES', 180),
    ],

];
