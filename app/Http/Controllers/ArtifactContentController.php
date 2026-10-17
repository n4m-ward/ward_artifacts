<?php

namespace App\Http\Controllers;

use App\Artifacts\ContentGateway;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;

/**
 * Serve os arquivos de uma versão (index.html e assets relativos) na origem
 * de conteúdo. Não usa sessão nem cookies: quem libera é o token do caminho.
 */
class ArtifactContentController extends Controller
{
    public function __invoke(ContentGateway $gateway, string $token, ?string $path = null): Response
    {
        $disk = Storage::disk(config('ward.artifacts.disk'));

        // Artboard de um design (token "b…"): sempre o HTML atual do artboard.
        if (str_starts_with($token, 'b')) {
            $board = $gateway->resolveBoard($token);

            abort_unless($board !== null && $board->storage_path !== '' && $disk->fileExists($board->storage_path), 404);

            return response($this->withRuntime((string) $disk->get($board->storage_path)), 200, $this->headers($gateway, 'index.html'));
        }

        $version = $gateway->resolve($token);

        abort_if($version === null, 404);

        $path = $this->normalize($path ?? '') ?? $version->entry_path;
        $file = $version->filePath($path);

        abort_unless($disk->fileExists($file), 404);

        return $disk->response($file, null, $this->headers($gateway, $path));
    }

    /**
     * Injeta o runtime do Ward Design antes do último </body> (ou no fim).
     */
    private function withRuntime(string $html): string
    {
        $script = '<script>'.file_get_contents(resource_path('design/runtime.js')).'</script>';
        $position = strripos($html, '</body>');

        return $position === false
            ? $html.$script
            : substr($html, 0, $position).$script.substr($html, $position);
    }

    /**
     * @return array<string, string>
     */
    private function headers(ContentGateway $gateway, string $path): array
    {
        return [
            'Content-Type' => $this->contentType($path),
            'Cache-Control' => 'private, max-age=300',
            'Content-Security-Policy' => "sandbox {$gateway->sandbox()}; frame-ancestors ".rtrim((string) config('app.url'), '/'),
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            // O token está no caminho: não pode vazar no Referer para CDNs externas.
            'Referrer-Policy' => 'no-referrer',
            // Fora do modo isolado o artifact roda numa origem opaca e precisa de
            // CORS para o fetch() de assets relativos. O token já é a credencial.
            'Access-Control-Allow-Origin' => '*',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ];
    }

    /**
     * Caminho relativo seguro dentro da versão, ou null para o arquivo de entrada.
     */
    private function normalize(string $path): ?string
    {
        $segments = array_filter(explode('/', $path), fn (string $s) => $s !== '' && $s !== '.');

        abort_if(in_array('..', $segments, true) || str_contains($path, "\0") || str_contains($path, '\\'), 404);

        return $segments === [] ? null : implode('/', $segments);
    }

    private function contentType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $type = match ($extension) {
            'html', 'htm' => 'text/html',
            'js', 'mjs' => 'text/javascript',
            'css' => 'text/css',
            'json', 'map', 'gltf' => 'application/json',
            'wasm' => 'application/wasm',
            default => MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? 'application/octet-stream',
        };

        return str_starts_with($type, 'text/') || $type === 'application/json' ? "{$type}; charset=utf-8" : $type;
    }
}
