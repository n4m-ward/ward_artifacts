<?php

namespace App\Mcp\Apps;

use App\Artifacts\ContentGateway;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\AppResource;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Ui\AppMeta;
use Laravel\Mcp\Server\Ui\Csp;

/**
 * MCP App do Ward Design: o canvas ao vivo dentro do chat (open_design) e a
 * prévia do artboard enquanto a IA escreve (upsert_artboard).
 *
 * Os artboards rodam em iframes do host de conteúdo, como na página do design;
 * o ao vivo usa a URL assinada direto ou, se o host bloquear, a ferramenta
 * design_live.
 */
#[Name('ward-design')]
#[Title('Ward Design')]
#[Description('Canvas ao vivo de um design do Ward Artifacts.')]
class DesignApp extends AppResource
{
    public function __construct(private ContentGateway $gateway) {}

    /**
     * A URI muda quando a interface muda, para o cliente não usar uma cópia velha.
     */
    public function uri(): string
    {
        $version = substr(md5(implode('|', array_map(
            fn (string $file) => (string) md5_file($file),
            [resource_path('views/mcp/design-app.blade.php'), resource_path('js/design-canvas.js'), resource_path('js/design-editor.js'), resource_path('views/designs/canvas-styles.blade.php')],
        ))), 0, 10);

        return "ui://ward-artifacts/design-{$version}";
    }

    public function appMeta(): AppMeta
    {
        return AppMeta::make()
            ->prefersBorder(false)
            ->csp(Csp::make()
                ->frameDomains([$this->gateway->origin()])
                ->connectDomains([$this->origin((string) config('app.url'))])
                ->resourceDomains(['https://fonts.googleapis.com', 'https://fonts.gstatic.com']));
    }

    public function handle(Request $request): Response
    {
        return Response::view('mcp.design-app', [
            'canvasScript' => (string) file_get_contents(resource_path('js/design-canvas.js')),
            // Editor completo (dono/admin edita; qualquer pessoa logada comenta), sem exportar.
            'editorScript' => (string) file_get_contents(resource_path('js/design-editor.js')),
        ]);
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
