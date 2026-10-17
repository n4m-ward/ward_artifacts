<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // Rotas OAuth do MCP: o registro de clientes é público e fica apertado;
        // a descoberta (.well-known) é lida a cada conexão, inclusive pelos IPs
        // compartilhados do Claude. Se ela devolver 429, o cliente cai no
        // /register padrão e a conexão falha com 404.
        RateLimiter::for('mcp-oauth', fn (Request $request) => $request->isMethod('post')
            ? Limit::perMinute(20)->by('register|'.$request->ip())
            : Limit::perMinute(600)->by('discovery|'.$request->ip()));

        // Tela onde o usuário aprova a conexão de um cliente MCP (Claude, Codex...).
        Passport::authorizationView(fn (array $parameters) => view('mcp.authorize', $parameters));

        // Clientes MCP renovam o acesso sozinhos com o refresh token.
        Passport::tokensExpireIn(now()->addDay());
        Passport::refreshTokensExpireIn(now()->addDays(60));
        Passport::personalAccessTokensExpireIn(now()->addDays(90));
    }
}
