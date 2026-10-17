<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Em produção tudo é HTTPS e as URLs saem sempre do APP_URL (o
        // repositório roda numa pasta reescrita para public/ na Hostinger).
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
            URL::forceRootUrl(config('app.url'));
        }

        // Tela onde o usuário aprova a conexão de um cliente MCP (Claude, Codex...).
        Passport::authorizationView(fn (array $parameters) => view('mcp.authorize', $parameters));

        // Clientes MCP renovam o acesso sozinhos com o refresh token.
        Passport::tokensExpireIn(now()->addDay());
        Passport::refreshTokensExpireIn(now()->addDays(60));
        Passport::personalAccessTokensExpireIn(now()->addDays(90));
    }
}
