<?php

namespace App\Mcp\Servers;

use App\Mcp\Apps\DesignApp;
use App\Mcp\Tools\AddDesignAssetTool;
use App\Mcp\Tools\CreateDesignTool;
use App\Mcp\Tools\DeleteArtboardTool;
use App\Mcp\Tools\DesignLiveTool;
use App\Mcp\Tools\DesignReportViewTool;
use App\Mcp\Tools\GetArtboardSourceTool;
use App\Mcp\Tools\GetArtifactSourceTool;
use App\Mcp\Tools\GetDesignGuideTool;
use App\Mcp\Tools\GetDesignTool;
use App\Mcp\Tools\GetTemplateTool;
use App\Mcp\Tools\InviteUserTool;
use App\Mcp\Tools\ListArtifactsTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\OpenDesignTool;
use App\Mcp\Tools\PublishArtifactTool;
use App\Mcp\Tools\RenameArtifactTool;
use App\Mcp\Tools\ResolveCommentTool;
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
- create_design → abra o canvas antes da primeira tela, para a pessoa ver você trabalhando:
  open_design se o cliente mostra MCP Apps (chat do Claude); senão abra o preview_url no seu
  navegador (ex.: Claude Code) → get_design_guide → defina a direção (fontes, paleta) → um
  upsert_artboard por tela → print da tela no seu navegador e correção até ficar sem defeitos (nenhuma
  tela está pronta sem você olhar) → update_layout → print do canvas inteiro.
- Alta fidelidade: conteúdo plausível (não [PREÇO]), fotos que você viu carregar, densidade de produto.
- Imagens do design: add_design_asset envia (base64) e devolve "assets/{nome}" para usar no HTML;
  get_design lista as que existem, inclusive as que a pessoa enviou no canvas.
- Para mudar uma tela: get_artboard_source → editar → upsert_artboard com o mesmo name.
- save_design_version para guardar um ponto. get_design mostra o canvas, o que a pessoa está vendo
  (artboard e elemento selecionados, erros de script), os comentários abertos e devolve preview_url.
- Comentários do canvas são pedidos da pessoa: aplique a mudança no artboard/elemento indicado e
  responda com resolve_comment (uma frase do que mudou).
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
        ResolveCommentTool::class,
        AddDesignAssetTool::class,
        DeleteArtboardTool::class,
        SaveDesignVersionTool::class,
        OpenDesignTool::class,
        DesignLiveTool::class,
        DesignReportViewTool::class,
    ];

    protected array $resources = [
        DesignApp::class,
    ];
}
