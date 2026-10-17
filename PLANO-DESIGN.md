# Ward Design — Plano

Um canvas de design dentro do Ward Artifacts, no espírito do Claude Design, alimentado pela IA via MCP.
A IA cria e altera **artboards** (frames de HTML) num canvas infinito; a pessoa acompanha **ao vivo**,
pelo link do canvas ou **dentro do próprio chat** (MCP App).

## Decisões

- Edição visual de texto e estilos (WYSIWYG): **sim, na fase D5**. Até lá o conteúdo é editado pela
  IA; a pessoa organiza, comenta e ajusta Tweaks.
- Comentários: **a IA lê e responde**. `get_design` devolve os abertos e a IA os marca como
  resolvidos ao corrigir.
- Ordem: **D1 → D2 → D3**, cada fase com deploy e teste em produção. Ward Code (D6) depois.

## O que vamos entregar

1. **Canvas ao vivo por link**: página `/{username}/{projeto}/{titulo}-{uuid}` do tipo design, com
   pan/zoom, artboards renderizados lado a lado, modo foco e modo Play. Atualiza sozinha enquanto a IA
   trabalha. Mesmas regras de acesso dos artifacts (só eu / usuários logados / público temporário,
   `preview_url` de uso único).
2. **MCP App**: a ferramenta `open_design` mostra o canvas dentro do Claude (claude.ai e desktop), com
   a prévia do artboard aparecendo **enquanto a IA escreve o HTML**.
3. **Ferramentas MCP de design** para a IA criar, editar, organizar e ler o canvas.
4. **Skill `ward-design`** ensinando o formato dos artboards e o fluxo.

Fica para uma etapa seguinte (já previsto na arquitetura): **suporte a MCP Apps no Ward Code**, para
mostrar o mesmo canvas dentro do harness da Ward.

## Paridade com o Claude Design

| Recurso do Claude Design | Ward Design | Fase |
|---|---|---|
| Canvas infinito com pan/zoom e artboards com nome | Sim | D2 |
| Artboard fixo (post, tela de app, pôster) e página fluida (site, dashboard) | Sim (`kind`: `fixed` / `page`) | D1 |
| Modo foco (um artboard em tela cheia) | Sim | D2 |
| Play: protótipo clicável com links entre artboards | Sim (`<a href="board:Nome">`) | D2 |
| Títulos e notas (stickies) no canvas | Sim | D2 |
| A IA sabe o que você está olhando/selecionando | Sim (página e MCP App reportam a seleção) | D3 |
| Tweaks (cores e opções ajustáveis num painel) | Sim (variáveis CSS declaradas no artboard) | D4 |
| Mover/redimensionar artboards à mão | Sim | D4 |
| Comentários ancorados que a IA lê | Sim | D4 |
| Edição visual de texto e estilos (WYSIWYG) | Sim | D5 |
| Exportar (PNG, PDF, HTML) | HTML/zip na D2; PNG/PDF na D5 | D2/D5 |
| Design systems (tokens, componentes) | Depois | — |
| Post-its coloridos com corda até uma tela ou componente (estilo Miro) | Sim | D7 |
| Upload de imagens para o design | Sim (assets do design) | D4 |

Nada do código do Claude Design é reaproveitado: o formato dos artboards é nosso (HTML comum, sem
runtime proprietário).

## Formato de um artboard

Um artboard é **um HTML autocontido**, igual a um artifact. Regras:

- `kind: "fixed"`: o elemento raiz tem exatamente `w`×`h` (ex.: 1080×1080, 390×844).
- `kind: "page"`: raiz fluida (`max-width`, `%`, `rem`); `w` é a largura de referência do frame.
- Fontes via Google Fonts, imagens em data URI ou como assets do design.
- **Links entre artboards**: `<a href="board:Checkout">` troca de artboard no modo Play.
- **Tweaks** (D4): um bloco opcional declarando variáveis CSS ajustáveis:
  `<script type="application/json" id="ward-tweaks">{"--accent":{"type":"color","default":"#38BDF8"}}</script>`.

Na hora de servir, o host de conteúdo **injeta um runtime pequeno da Ward** (não fica salvo no HTML):
ele resolve os links `board:`, aplica os Tweaks via `postMessage` e informa o tamanho real do conteúdo
para o canvas. Assim o arquivo continua abrindo sozinho no navegador.

## Modelo de dados

O design é um **artifact com `type = design`** e reaproveita URL, projeto, dono, visibilidade, link
público temporário, visualizações, painel e `preview_url`.

```
artifacts            + type (page|design), + design_revision (inteiro, sobe a cada mudança)
design_boards        id, artifact_id, name (único no design), title, kind (fixed|page),
                     x, y, w, h, interactive, sort, revision, storage_path, size_bytes,
                     updated_by, timestamps
design_notes         id, artifact_id, key, kind (title|sticky), x, y, w, text, color, timestamps
design_comments (D4) id, artifact_id, board_id, user_id, x, y (relativos ao artboard), body,
                     resolved_at, timestamps
design_snapshots     id, artifact_id, number, storage_path (zip do canvas), created_by, created_at
```

- O HTML de cada artboard fica em disco (`artifacts/{uuid}/boards/{board}/{revision}.html`); a linha
  em `design_boards` guarda o layout. Layout em banco permite edições parciais simultâneas (a IA muda
  um artboard enquanto você move outro) sem sobrescrever o canvas inteiro.
- `design_revision` é o relógio do canvas: toda mutação incrementa e o "ao vivo" só pergunta se ele
  mudou.
- **Versões**: o canvas é editado em cópia de trabalho; `save_design_version` (IA) ou "Salvar versão"
  (tela) congela um snapshot (zip com `canvas.json` + artboards), mantendo os 10 últimos.

## Ao vivo (sem WebSocket)

A Hostinger compartilhada não mantém conexões abertas, então o ao vivo é por **consulta curta**:

- `GET /d/{uuid}/live?rev=N` → `204` se nada mudou; senão o diff (artboards e notas alterados,
  com novas URLs de conteúdo).
- A página consulta a cada **1,5 s** com a aba visível e espaça para 10 s quando oculta. A consulta só lê
  `design_revision` (cacheado em arquivo), então custa muito pouco.
- Cada artboard recarrega só o próprio iframe quando a `revision` dele muda (sem piscar o canvas).
- A mesma rota atende o MCP App (via ferramenta só da interface) e o futuro Ward Code.

## Ferramentas MCP

| Ferramenta | Quem vê | O que faz |
|---|---|---|
| `create_design(title, project)` | IA | Cria o canvas (privado) e devolve `url` e `preview_url` |
| `open_design(design)` | IA + app | **Abre o canvas dentro do chat** (MCP App) |
| `upsert_artboard(design, name, html, kind, w, h, x?, y?, title?, interactive?)` | IA | Cria ou atualiza um artboard; sem `x,y` é posicionado sozinho na fileira |
| `update_layout(design, boards[], notes[])` | IA | Move/redimensiona/renomeia artboards e notas em lote |
| `resolve_comment(design, comment, reply?)` | IA | Responde e marca um comentário como resolvido (D4) |
| `get_design(design)` | IA | Layout, lista de artboards, comentários abertos e **o que a pessoa está selecionando** |
| `get_artboard_source(design, name)` | IA | HTML de um artboard para editar |
| `delete_artboard(design, name)` | IA | Remove um artboard |
| `save_design_version(design, label?)` | IA | Congela uma versão |
| `get_design_guide()` | IA | Regras do formato, tamanhos comuns e um esqueleto de artboard |
| `design_live(design, rev)` | só app | Diff para o MCP App atualizar |
| `design_report_view(design, selection)` | só app | O app informa a seleção da pessoa |

Todas reaproveitam as regras de permissão dos artifacts (dono/admin editam; quem pode ver, lê).

## Página do canvas

- **Barra**: título, projeto, versão, selo de visibilidade (abre Compartilhar), menu "⋯" (renomear,
  salvar versão, baixar zip, abrir artboard sozinho).
- **Canvas**: fundo pontilhado, pan (arrastar/trackpad), zoom (pinça, Ctrl+roda, atalhos, "ajustar
  tudo"), artboards em iframes do host de conteúdo com faixa de nome, títulos e notas.
- **Foco**: duplo clique abre um artboard sozinho em tela cheia (página fluida ocupa a janela).
- **Play**: artboards interativos ganham botão Play; `board:` navega entre eles.
- **Indicador "IA editando…"** no artboard que acabou de mudar.
- Mobile: o canvas vira lista vertical de artboards com modo foco.
- Atalhos e controles acessíveis por teclado.

## MCP App (dentro do Claude)

- `open_design` mostra o canvas compacto no chat, com botão de **tela cheia**.
- **Prévia em streaming**: quando a IA chama `upsert_artboard`, o app recebe o HTML parcial
  (`onToolInputPartial`) e já desenha o artboard sendo escrito.
- Atualiza pelo `design_live` a cada 1,5 s enquanto aberto.
- Selecionar um artboard envia o contexto à IA (`updateModelContext`), e uma caixa "Pedir mudança"
  manda a mensagem para o modelo (`sendMessage`).
- Os artboards rodam em iframes do host de conteúdo (`frameDomains`); se o cliente não permitir iframes
  aninhados, o app mostra miniaturas com link para o canvas.

Clientes sem MCP Apps (Claude Code no terminal, Codex, OpenCode) recebem texto + `url` + `preview_url`.

## Segurança

- Artboards rodam no host de conteúdo, em sandbox, com o mesmo token assinado de curta duração dos
  artifacts; nada de HTML de artboard no domínio do app.
- O runtime injetado conversa com a página só por `postMessage` com origem verificada.
- `design_live` exige a mesma permissão de visualização; o `preview_url` vale para o canvas.
- Limites: 200 artboards por design, 5 MB por artboard (HTML inline), 100 MB por design com assets.

## Fases

| # | Fase | Entrega |
|---|---|---|
| D1 ✅ | Base | `type=design`, tabelas, ações (criar, upsert, layout, apagar, versão), ferramentas MCP, skill `ward-design`, página simples do canvas com zoom |
| D2 ✅ | Canvas ao vivo | Página com pan/zoom, foco, Play, notas, ao vivo por consulta curta, compartilhar, zip |
| D3 | MCP App | `open_design` no Claude, prévia em streaming, seleção → IA, "Pedir mudança" |
| D4 | Edição humana | Mover/redimensionar, notas, Tweaks, comentários que a IA lê, upload de imagens |
| D5 | Avançado | Edição visual de texto e estilos, exportar PNG/PDF, histórico de versões na tela |
| D6 | Ward Code | Suporte a MCP Apps no harness da Ward (renderizar `ui://` + ponte de mensagens) |
| D7 | Post-its | Post-its coloridos para outras pessoas, com corda opcional até um artboard ou componente (detalhes abaixo) |

Cada fase termina com testes, deploy pela `main` e verificação em produção (como nas fases anteriores).

## D7 — Post-its (no fim, depois de tudo pronto)

Post-its no canvas, como num Miro. Ficam para depois de todas as outras fases.

- **Para que serve**: um aviso para **outras pessoas** sobre algo específico do design ("esta tela
  ainda depende da API", "cor aprovada pelo cliente"). **Não é comentário para a IA.** Comentários
  (D4) são pedidos de mudança que a IA lê e resolve; o post-it é informativo e fica no canvas.
- **A IA também enxerga**: `get_design` lista os post-its (texto, cor, posição e a que estão ligados).
  A IA pode levá-los em conta como contexto, mas não precisa "resolver" nem responder.
- **Cores variadas**: paleta de cores de post-it (amarelo, rosa, verde, azul, laranja, roxo…),
  escolhida por quem cria.
- **Corda (opcional)**: um post-it pode ser ligado por uma linha:
  - a um **artboard** inteiro; ou
  - a um **componente dentro de um artboard** (um elemento da tela), ancorado num seletor estável
    ou num ponto relativo ao artboard, como os comentários da D4.

  A corda acompanha o post-it e o artboard quando um ou outro é movido. Sem corda, o post-it fica
  solto no canvas.
- **Quem cria e edita**: pessoas com permissão de edição criam, movem, mudam a cor, editam o texto e
  apagam pela tela. A IA também pode criar e editar por MCP (`update_layout`).
- **Dados** (rascunho): as notas `sticky` atuais ganham `board_id` (nullable), `anchor` (seletor ou
  ponto, nullable) e `author_id`; a corda é desenhada a partir desses campos.
