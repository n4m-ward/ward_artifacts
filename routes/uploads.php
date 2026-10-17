<?php

use App\Http\Controllers\ArtifactUploadController;
use Illuminate\Support\Facades\Route;

// Links de upload de uso único gerados pelo MCP. O próprio token autentica.
Route::match(['put', 'post'], 'uploads/{token}', ArtifactUploadController::class)
    ->middleware('throttle:30,1')
    ->where('token', '[A-Za-z0-9]{48}')
    ->name('artifact-uploads.store');
