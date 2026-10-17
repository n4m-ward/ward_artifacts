<?php

use App\Http\Middleware\LogMcpRequests;
use App\Http\Middleware\TrackMcpTokenUsage;
use App\Mcp\Servers\WardServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// Descoberta OAuth (.well-known) e registro dinâmico de clientes MCP. O
// registro é público, então fica com limite de taxa.
Route::middleware('throttle:20,1')->group(fn () => Mcp::oauthRoutes());

Mcp::web('/mcp', WardServer::class)
    ->middleware(['auth:api', TrackMcpTokenUsage::class, LogMcpRequests::class, 'throttle:120,1'])
    ->name('mcp');
