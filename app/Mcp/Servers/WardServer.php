<?php

namespace App\Mcp\Servers;

use App\Mcp\Apps\DesignApp;
use App\Mcp\Tools\AddDesignAssetTool;
use App\Mcp\Tools\CreateDesignTool;
use App\Mcp\Tools\DeleteArtboardTool;
use App\Mcp\Tools\DesignAppRequestTool;
use App\Mcp\Tools\DesignBoardHtmlTool;
use App\Mcp\Tools\DesignLiveTool;
use App\Mcp\Tools\DesignRenderPreviewTool;
use App\Mcp\Tools\DesignReportViewTool;
use App\Mcp\Tools\GetArtboardSourceTool;
use App\Mcp\Tools\GetArtifactSourceTool;
use App\Mcp\Tools\GetDesignGuideTool;
use App\Mcp\Tools\GetDesignSystemTool;
use App\Mcp\Tools\GetDesignTool;
use App\Mcp\Tools\GetTemplateTool;
use App\Mcp\Tools\ImportComponentTool;
use App\Mcp\Tools\InviteUserTool;
use App\Mcp\Tools\ListArtifactsTool;
use App\Mcp\Tools\ListDesignSystemsTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\OpenDesignTool;
use App\Mcp\Tools\PublishArtifactTool;
use App\Mcp\Tools\PublishComponentTool;
use App\Mcp\Tools\RenameArtifactTool;
use App\Mcp\Tools\ResolveCommentTool;
use App\Mcp\Tools\SaveDesignSystemTool;
use App\Mcp\Tools\SaveDesignVersionTool;
use App\Mcp\Tools\SetDesignTokensTool;
use App\Mcp\Tools\ShowInCanvasTool;
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

Skills: se você tiver as skills ward-artifact-create, ward-artifact-publish, ward-artifact-edit ou
ward-design (WardCode e Claude Code trazem), carregue a que corresponde à tarefa ANTES de usar estas
ferramentas: elas têm o passo a passo completo e as regras de qualidade.

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
  open_design UMA vez se o cliente mostra MCP Apps (chat do Claude, aba App do WardCode; ele segue
  você e mostra cada tela nova, não chame de novo a cada tela); senão abra o
  preview_url no seu navegador (ex.: Claude Code) → get_design_guide → defina a direção (fontes, paleta) → um
  upsert_artboard por tela → print da tela no seu navegador e correção até ficar sem defeitos (nenhuma
  tela está pronta sem você olhar) → update_layout → print do canvas inteiro.
- Alta fidelidade: conteúdo plausível (não [PREÇO]), fotos que você viu carregar, densidade de produto.
- Tokens (set_design_tokens): cores, fontes e raios do design como variáveis CSS no :root de todos os
  artboards; use var(--nome) nas telas. Componentes: um artboard pode ser usado dentro de outro com
  <ward-include name="Header"></ward-include> (muda no componente, muda em todos). Páginas:
  update_layout.pages cria abas; upsert_artboard.page coloca a tela numa página.
- Design systems (um por projeto, visíveis para todos): list_design_systems e get_design_system antes de
  desenhar; reaproveite com import_component (link ou copy) ou <ward-include ds="usuario/projeto"
  name="X">. Crie/documente com save_design_system; promova peças com publish_component.
- Imagens do design: add_design_asset envia (base64) e devolve "assets/{nome}" para usar no HTML;
  get_design lista as que existem, inclusive as que a pessoa enviou no canvas.
- Quem vê o canvas segue você (câmera e cursor com o seu nome). Conduza com show_in_canvas (focus,
  point, fit) e TESTE protótipos com show_in_canvas click: a resposta diz em que tela o Play ficou.
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
        ShowInCanvasTool::class,
        UpdateDesignLayoutTool::class,
        GetDesignTool::class,
        GetArtboardSourceTool::class,
        ResolveCommentTool::class,
        AddDesignAssetTool::class,
        SetDesignTokensTool::class,
        ListDesignSystemsTool::class,
        GetDesignSystemTool::class,
        SaveDesignSystemTool::class,
        ImportComponentTool::class,
        PublishComponentTool::class,
        DeleteArtboardTool::class,
        SaveDesignVersionTool::class,
        OpenDesignTool::class,
        DesignLiveTool::class,
        DesignBoardHtmlTool::class,
        DesignRenderPreviewTool::class,
        DesignReportViewTool::class,
        DesignAppRequestTool::class,
    ];

    protected array $resources = [
        DesignApp::class,
    ];
}
