<?php

use App\Http\Controllers\ArtifactDownloadController;
use App\Http\Controllers\ArtifactViewerController;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');

    Volt::route('users', 'users.index')
        ->middleware('can:viewAny,'.User::class)
        ->name('users.index');
});

require __DIR__.'/auth.php';

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
