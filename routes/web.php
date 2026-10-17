<?php

use App\Http\Controllers\ArtifactDownloadController;
use App\Http\Controllers\ArtifactPreviewController;
use App\Http\Controllers\ArtifactViewerController;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Não há página pública: quem não está logado vai para o login.
Route::redirect('/', '/dashboard')->name('home');

Volt::route('dashboard', 'artifacts.index')
    ->middleware('auth')
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');

    Volt::route('connections', 'connections.index')->name('connections.index');

    Volt::route('users', 'users.index')
        ->middleware('can:viewAny,'.User::class)
        ->name('users.index');
});

require __DIR__.'/auth.php';

// Pré-visualização de uso único devolvida pelo MCP (para o navegador da IA).
Route::get('p/{token}', ArtifactPreviewController::class)
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware('throttle:60,1')
    ->name('artifacts.preview');

Route::get('artifacts/{artifact}/download', ArtifactDownloadController::class)
    ->name('artifacts.download');

// Fica por último: /{username}/{projeto}/{titulo}-{uuid}.html
Route::get('{username}/{project}/{file}', ArtifactViewerController::class)
    ->where([
        'username' => '[a-z0-9-]+',
        'project' => '[a-z0-9-]+',
        'file' => '[A-Za-z0-9._%-]*[0-9a-f]{32}\\.html',
    ])
    ->name('artifacts.show');
