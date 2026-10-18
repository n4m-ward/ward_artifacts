<?php

use App\Http\Controllers\ArtifactDownloadController;
use App\Http\Controllers\ArtifactPreviewController;
use App\Http\Controllers\ArtifactViewerController;
use App\Http\Controllers\DesignCommentController;
use App\Http\Controllers\DesignEditController;
use App\Http\Controllers\DesignLiveController;
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

// Ao vivo do canvas de design: o navegador pergunta a cada ~1,5 s se mudou.
Route::get('d/{artifact}/live', DesignLiveController::class)
    ->where('artifact', '[0-9a-f]{32}')
    ->middleware('throttle:240,1')
    ->name('designs.live');

// Edição à mão no canvas web (dono do design ou admin).
Route::middleware(['auth', 'throttle:600,1'])->where(['artifact' => '[0-9a-f]{32}', 'board' => '[0-9]+', 'comment' => '[0-9]+'])->group(function () {
    Route::post('d/{artifact}/edit', [DesignEditController::class, 'ops'])->name('designs.edit');
    Route::get('d/{artifact}/boards/{board}/source', [DesignEditController::class, 'source'])->name('designs.board-source');

    // Comentários e o que a pessoa está vendo (lidos pela IA no get_design).
    Route::post('d/{artifact}/comments', [DesignCommentController::class, 'store'])->name('designs.comments.store');
    Route::patch('d/{artifact}/comments/{comment}', [DesignCommentController::class, 'update'])->name('designs.comments.update');
    Route::delete('d/{artifact}/comments/{comment}', [DesignCommentController::class, 'destroy'])->name('designs.comments.destroy');
    Route::post('d/{artifact}/view', [DesignCommentController::class, 'view'])->name('designs.view');
});

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
