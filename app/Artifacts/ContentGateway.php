<?php

namespace App\Artifacts;

use App\Models\ArtifactVersion;
use App\Models\DesignBoard;

/**
 * Origem que serve o HTML bruto dos artifacts e os tokens que liberam esse
 * conteúdo. O token é uma capacidade assinada (HMAC) para uma versão, com
 * prazo: a moldura do viewer gera um token novo a cada abertura.
 */
class ContentGateway
{
    /** Permissões do sandbox em qualquer modo. */
    private const BASE_SANDBOX = [
        'allow-scripts', 'allow-forms', 'allow-modals', 'allow-popups',
        'allow-popups-to-escape-sandbox', 'allow-downloads', 'allow-pointer-lock',
    ];

    /**
     * True quando o conteúdo sai de um host diferente do app. Só aí o
     * artifact pode ter allow-same-origin (e, com isso, storage).
     */
    public function isIsolated(): bool
    {
        $host = $this->host();

        return $host !== null && $host !== parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    public function host(): ?string
    {
        $url = config('ward.artifacts.content_url');

        return $url ? parse_url($url, PHP_URL_HOST) : null;
    }

    public function origin(): string
    {
        $url = config('ward.artifacts.content_url') ?: config('app.url');

        return rtrim((string) $url, '/');
    }

    public function sandbox(): string
    {
        $flags = self::BASE_SANDBOX;

        if ($this->isIsolated()) {
            $flags[] = 'allow-same-origin';
        }

        return implode(' ', $flags);
    }

    public function url(ArtifactVersion $version, ?string $path = null, ?int $ttlMinutes = null): string
    {
        $expiresAt = $ttlMinutes !== null ? now()->addMinutes($ttlMinutes)->timestamp : null;

        return $this->origin().'/c/'.$this->token($version, $expiresAt).'/'.ltrim($path ?? $version->entry_path, '/');
    }

    public function token(ArtifactVersion $version, ?int $expiresAt = null): string
    {
        $expiresAt ??= now()->addMinutes((int) config('ward.artifacts.content_ttl_minutes'))->timestamp;
        $payload = "v{$version->id}.{$expiresAt}";

        return $payload.'.'.$this->sign($payload);
    }

    /**
     * Devolve a versão liberada pelo token, ou null se ele for inválido,
     * vencido ou se a versão não existir mais.
     */
    public function resolve(string $token): ?ArtifactVersion
    {
        $id = $this->verify('v', $token);

        return $id === null ? null : ArtifactVersion::with('artifact')->find($id);
    }

    /**
     * URL de um artboard de design. A revisão vai no caminho: quando o HTML
     * muda, a URL muda e nenhum cache antigo é usado.
     */
    public function boardUrl(DesignBoard $board, ?int $ttlMinutes = null): string
    {
        $expiresAt = now()->addMinutes($ttlMinutes ?? (int) config('ward.artifacts.content_ttl_minutes'))->timestamp;
        $payload = "b{$board->id}.{$expiresAt}";

        return $this->origin().'/c/'.$payload.'.'.$this->sign($payload).'/'.$board->revision.'.html';
    }

    public function resolveBoard(string $token): ?DesignBoard
    {
        $id = $this->verify('b', $token);

        return $id === null ? null : DesignBoard::with('artifact')->find($id);
    }

    /**
     * Confere assinatura e prazo de um token "{prefixo}{id}.{expira}.{hmac}".
     */
    private function verify(string $prefix, string $token): ?int
    {
        if (! preg_match('/^'.$prefix.'(\d+)\.(\d+)\.([A-Za-z0-9_-]{43})$/', $token, $m)) {
            return null;
        }

        [, $id, $expiresAt, $signature] = $m;

        if (! hash_equals($this->sign("{$prefix}{$id}.{$expiresAt}"), $signature)
            || (int) $expiresAt < now()->timestamp) {
            return null;
        }

        return (int) $id;
    }

    private function sign(string $payload): string
    {
        $key = hash_hmac('sha256', 'ward-artifact-content', (string) config('app.key'), true);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $key, true)), '+/', '-_'), '=');
    }
}
