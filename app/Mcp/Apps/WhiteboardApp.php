<?php

namespace App\Mcp\Apps;

use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\AppResource;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Ui\AppMeta;
use Laravel\Mcp\Server\Ui\Csp;

/**
 * MCP App do Ward Whiteboard: o quadro ao vivo dentro do chat (open_whiteboard),
 * só leitura, seguindo a IA. Carrega o mesmo pacote da página do quadro
 * (Excalidraw) a partir do app; o ao vivo usa a URL assinada ou, se o host
 * bloquear, a ferramenta whiteboard_live.
 */
#[Name('ward-whiteboard')]
#[Title('Ward Whiteboard')]
#[Description('Whiteboard ao vivo do Ward Artifacts.')]
class WhiteboardApp extends AppResource
{
    /**
     * A URI muda quando a interface ou o pacote compilado mudam (o cliente não usa cópia velha).
     */
    public function uri(): string
    {
        $manifest = public_path('build/manifest.json');
        $version = substr(md5((string) md5_file(resource_path('views/mcp/whiteboard-app.blade.php')).(File::exists($manifest) ? md5_file($manifest) : '')), 0, 10);

        return "ui://ward-artifacts/whiteboard-{$version}";
    }

    public function appMeta(): AppMeta
    {
        $app = $this->origin((string) config('app.url'));

        return AppMeta::make()
            ->prefersBorder(false)
            ->csp(Csp::make()
                ->frameDomains([$app, ...array_map(fn ($host) => "https://{$host}", (array) config('ward.whiteboards.embed_hosts'))])
                ->connectDomains([$app])
                ->resourceDomains([$app]));
    }

    public function handle(Request $request): Response
    {
        return Response::view('mcp.whiteboard-app');
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
