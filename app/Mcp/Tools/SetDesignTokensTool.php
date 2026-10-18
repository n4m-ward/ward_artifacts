<?php

namespace App\Mcp\Tools;

use App\Actions\Designs\SetDesignTokens;
use App\Designs\InvalidDesign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_design_tokens')]
#[Description('Define os tokens do design: declarações de variáveis CSS (ex.: "--ink: #1c1917; --accent: oklch(58% 0.15 38); --display: \'Instrument Serif\', serif;") que entram no :root de TODOS os artboards. Use var(--nome) no CSS das telas em vez de repetir cores e fontes; mudar um token atualiza todas as telas. Substitui o bloco inteiro (leia o atual no get_design).')]
class SetDesignTokensTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
            'css' => $schema->string()->description('Só declarações (--nome: valor;), sem :root, <style> ou seletores. Vazio apaga os tokens.')->required(),
        ];
    }

    public function handle(Request $request, SetDesignTokens $tokens): Response|ResponseFactory
    {
        $validated = $request->validate(['design' => ['required', 'string'], 'css' => ['present', 'nullable', 'string']]);

        $design = $this->findDesign($request, $validated['design']);
        if ($design instanceof Response) {
            return $design;
        }

        if ($error = $this->cannotEdit($request, $design)) {
            return $error;
        }

        try {
            $tokens->handle($design, (string) ($validated['css'] ?? ''));
        } catch (InvalidDesign $e) {
            return Response::error($e->getMessage());
        }

        $count = count(SetDesignTokens::parse($design->fresh()->design_tokens));

        return Response::text("Tokens salvos ({$count} variável(is)). Todos os artboards já usam. Design: {$design->url()}");
    }
}
