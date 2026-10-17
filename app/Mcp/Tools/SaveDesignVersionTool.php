<?php

namespace App\Mcp\Tools;

use App\Actions\Designs\SaveDesignSnapshot;
use App\Designs\InvalidDesign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('save_design_version')]
#[Description('Congela o canvas atual (todos os artboards e o layout) como uma versão numerada. Use ao chegar num ponto que valha guardar, ou antes de uma mudança grande.')]
class SaveDesignVersionTool extends WardTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'design' => $schema->string()->description('Id (32 caracteres) ou URL do design.')->required(),
            'label' => $schema->string()->max(120)->description('Descrição curta da versão (opcional).'),
        ];
    }

    public function handle(Request $request, SaveDesignSnapshot $save): Response|ResponseFactory
    {
        $request->validate(['design' => ['required', 'string'], 'label' => ['nullable', 'string', 'max:120']]);

        $design = $this->findDesign($request, $request->get('design'));
        if ($design instanceof Response) {
            return $design;
        }

        if ($error = $this->cannotEdit($request, $design)) {
            return $error;
        }

        try {
            $snapshot = $save->handle($design, $this->user($request), $request->get('label'));
        } catch (InvalidDesign $e) {
            return Response::error($e->getMessage());
        }

        return Response::make(Response::text("Versão {$snapshot->number} salva com {$snapshot->board_count} artboard(s)."))
            ->withStructuredContent(['design' => $design->uuid, 'version' => $snapshot->number]);
    }
}
