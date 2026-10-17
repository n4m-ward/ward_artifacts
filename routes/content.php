<?php

use App\Artifacts\ContentGateway;
use App\Http\Controllers\ArtifactContentController;
use Illuminate\Support\Facades\Route;

// HTML bruto dos artifacts. Sem sessão, sem cookies: o token do caminho libera.
// Com WARD_CONTENT_URL estas rotas existem só no host de conteúdo.
$routes = function () {
    Route::get('c/{token}/{path?}', ArtifactContentController::class)
        ->where('token', 'v[0-9]+\.[0-9]+\.[A-Za-z0-9_-]{43}')
        ->where('path', '.*')
        ->name('artifacts.content');
};

$gateway = app(ContentGateway::class);

$gateway->isIsolated()
    ? Route::domain($gateway->host())->group($routes)
    : $routes();
