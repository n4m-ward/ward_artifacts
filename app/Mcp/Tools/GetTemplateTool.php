<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_template')]
#[Description('Retorna o esqueleto HTML padrão da Ward para começar um artifact. "tailwind" (padrão): Tailwind v4 + tokens de cor com tema escuro. "plain": CSS puro com os mesmos tokens, sem dependências. "canvas": palco em tela cheia com loop requestAnimationFrame, para jogos e simulações.')]
#[IsReadOnly]
#[IsIdempotent]
class GetTemplateTool extends WardTool
{
    public const VARIANTS = ['tailwind', 'plain', 'canvas'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'variant' => $schema->string()->enum(self::VARIANTS)->description('Qual esqueleto usar. Padrão: tailwind.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate(['variant' => ['nullable', 'in:'.implode(',', self::VARIANTS)]]);

        $variant = $request->get('variant') ?: 'tailwind';

        return Response::text(file_get_contents(resource_path("artifact-templates/{$variant}.html")));
    }
}
