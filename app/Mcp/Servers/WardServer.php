<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateDesignTool;
use App\Mcp\Tools\DeleteArtboardTool;
use App\Mcp\Tools\GetArtboardSourceTool;
use App\Mcp\Tools\GetArtifactSourceTool;
use App\Mcp\Tools\GetDesignGuideTool;
use App\Mcp\Tools\GetDesignTool;
use App\Mcp\Tools\GetTemplateTool;
use App\Mcp\Tools\InviteUserTool;
use App\Mcp\Tools\ListArtifactsTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\PublishArtifactTool;
use App\Mcp\Tools\RenameArtifactTool;
use App\Mcp\Tools\SaveDesignVersionTool;
use App\Mcp\Tools\UpdateArtifactTool;
use App\Mcp\Tools\UpdateDesignLayoutTool;
use App\Mcp\Tools\UpsertArtboardTool;
use App\Mcp\Tools\WhoAmITool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Ward Artifacts')]
#[Version('1.0.0')]
#[Instructions(<<<'TXT'
Ward Artifacts publica páginas HTML geradas por IA em artifacts.wardtecnology.com.

Fluxo:
1. Comece pelo get_template (tailwind por padrão; canvas para jogos/simulações) e escreva o artifact
   como um único .html autocontido: CSS e JS inline, imagens em data: URI. Recursos externos só quando
   inline não der. Se houver muitos assets binários, faça um bundle .zip com index.html na raiz e
   caminhos relativos (assets/...). O arquivo deve abrir direto no navegador, sem servidor.
2. Publique com publish_artifact. "project" é a feature/assunto da conversa atual (não o repositório);
   reaproveite nomes de list_projects. HTML até 2 MB vai no campo "html"; acima disso, ou para .zip,
   omita "html", pegue o upload_url e envie com curl.
3. Para mudar um artifact existente use update_artifact (mesma URL), nunca publique um novo.
   Para editar, leia o código com get_artifact_source.
4. Todo artifact nasce privado. Só o usuário pode compartilhar ou tornar público, na tela do artifact.
   Não diga que o link é público.
5. publish_artifact, update_artifact, get_artifact_source e o upload devolvem preview_url: um link de
   uso único, sem login, para você abrir o artifact no seu navegador e conferir o resultado. Ele morre
   no primeiro acesso; ao usuário, entregue sempre a URL normal (url).
6. Limite: 100 MB por artifact. localStorage pode falhar: use try/catch.

Ward Design (canvas com vários artboards, para mockups, telas de app, landing pages e posts):
- create_design → get_design_guide → um upsert_artboard por tela → update_layout para organizar.
- Para mudar uma tela: get_artboard_source → editar → upsert_artboard com o mesmo name.
- save_design_version para guardar um ponto. get_design mostra o canvas e devolve preview_url.
TXT)]
class WardServer extends Server
{
    protected array $tools = [
        GetTemplateTool::class,
        PublishArtifactTool::class,
        UpdateArtifactTool::class,
        GetArtifactSourceTool::class,
        ListArtifactsTool::class,
        ListProjectsTool::class,
        RenameArtifactTool::class,
        WhoAmITool::class,
        InviteUserTool::class,
        CreateDesignTool::class,
        GetDesignGuideTool::class,
        UpsertArtboardTool::class,
        UpdateDesignLayoutTool::class,
        GetDesignTool::class,
        GetArtboardSourceTool::class,
        DeleteArtboardTool::class,
        SaveDesignVersionTool::class,
    ];
}
