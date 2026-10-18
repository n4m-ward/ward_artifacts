---
name: ward-design
description: Design screens and visuals on a Ward Design canvas (artifacts.wardtecnology.com) through the ward-artifacts MCP server. Use when the user wants mockups, app screens, UI flows, landing page or website layouts, social posts, banners, posters, or several visual variations side by side — "design", "mockup", "tela", "protótipo", "post", "layout", "variações", "Ward Design" — rather than a single standalone page. Covers opening the live canvas before editing so the user watches the work, setting a design direction, high-fidelity craft, reviewing a screenshot of every artboard in the embedded browser before calling it done, clickable prototypes with board: links, editing an artboard, and saving versions.
---

# Ward Design

Um **design** é um canvas com vários **artboards** lado a lado. Cada artboard é um HTML autocontido
(uma tela, um post, uma página). Use um design quando houver **várias telas ou variações**; para uma
única página use a skill `ward-artifact-create`.

O usuário quer **ver você desenhando**: o canvas fica aberto desde o início e atualiza sozinho a cada
`upsert_artboard`. E o padrão é **alta fidelidade**: telas com cara de produto pronto, não wireframes.

## 1. Abrir o canvas antes de qualquer tela

1. `create_design(title, project)`: `project` é a feature da conversa (veja `list_projects`). Guarde o
   `id`, a `url` e o `preview_url`. Para um design que já existe, `get_design` devolve o mesmo.
2. **Abra o canvas vazio agora**, antes do primeiro `upsert_artboard`:
   - **Claude Code e outros clientes com navegador**: abra o `preview_url` no seu navegador (no app
     desktop, o painel de navegador; senão o Chrome). Ele entra sem login e o canvas fica ao vivo
     enquanto a aba estiver aberta. O link é de uso único: se precisar abrir de novo, peça outro com
     `get_design`. A aba Code do app desktop **não** mostra MCP Apps, então não conte com o
     `open_design` ali.
   - **Chat do claude.ai ou do app desktop (MCP Apps)**: `open_design(design)` mostra o canvas no
     próprio chat.
   - Sem navegador nenhum: passe a `url` ao usuário e diga que ela atualiza sozinha.
3. Diga ao usuário que o canvas está aberto e que as telas vão aparecendo ali.

## 2. Direção antes do HTML

1. `get_design_guide` **antes do primeiro artboard**: regras do formato, critérios de acabamento e
   esqueletos (só estrutura: as fontes e cores são suas).
2. Se faltar contexto (produto, público, plataforma, marca), pergunte de forma breve. Se o usuário tem
   marca, site, código ou design system, parta dele.
3. Mostre a direção em 3–4 linhas: adjetivo e referência, par de fontes, paleta em `oklch()`, claro ou
   escuro. Essa direção vale para **todas** as telas: o mesmo bloco `:root` em cada artboard.

## 3. Uma tela por vez, avaliando o print de cada uma

Nenhuma tela está pronta sem você olhar a imagem dela. O ciclo de cada tela é:

1. `upsert_artboard` com `name` curto em ASCII (`Home`, `Cardapio`), `title` com acento se precisar,
   `kind`, `w`, `h` e o `html` completo.
   - `kind: "fixed"`: post, story, tela de app, pôster, ou seja, tamanho exato (390×844, 1080×1080…).
   - `kind: "page"`: site, landing, dashboard, ou seja, raiz fluida; `w` 1280–1440 e `h` a altura real.
2. **Tire um print no navegador embutido** (no Claude Code, o painel de navegador com o canvas aberto
   pela seção 1). O canvas atualiza sozinho; espere a tela nova aparecer.
   - Uma tela: abra o artboard em foco (clique duplo) ou use zoom na área dele.
   - O conjunto: "ajustar tudo" (tecla 1) e print do canvas inteiro.
   - Os iframes do canvas às vezes demoram alguns segundos para pintar na captura: espere e capture de
     novo antes de concluir que a tela está em branco.
3. Na imagem, procure: conteúdo cortado ou atrás de barras fixas, texto quebrando ou vazando, fotos que
   não carregaram, fonte de fallback, alinhamento e espaçamento irregulares, contraste fraco, hierarquia
   confusa, inconsistência com as outras telas, áreas vazias com cara de inacabado.
4. Achou algo: corrija com `upsert_artboard` do mesmo `name` e tire o print de novo. Repita até a tela
   estar sem defeitos; só então siga para a próxima.
5. Com todas as telas prontas: `update_layout` para organizar (fileiras com 80 px entre artboards, 160 px
   entre fileiras, títulos `kind: "title"` e notas adesivas) e um print do canvas inteiro para avaliar o
   conjunto.
6. No fim, passe ao usuário a **URL do design** (`url`), nunca o `preview_url`. Diga que avaliou os
   prints e o que corrigiu. Se não teve como ver as telas, diga isso.

## Qualidade (resumo do guia)

- Densidade de produto real: barra de status, navegação, ações e o conteúdo que existiria na tela.
- Conteúdo plausível e coerente entre as telas: nomes, preços e números verossímeis. Nada de lorem
  ipsum nem `[PREÇO]`, a menos que o usuário peça wireframe.
- Fotos: as do usuário, ou do Unsplash por URL **depois de ver que carregaram e combinam**. Sem foto
  confiável, um placeholder honesto na paleta; nunca um gradiente fingindo ser foto.
- Tipografia com intenção (não Inter, Roboto, Arial, Space Grotesk nem fonte do sistema por padrão),
  escala com contraste real, ícones SVG com traço consistente, sem emoji.
- Fuja dos vícios de "tela de IA": gradiente roxo/azul, brilho radial no topo, cartão com borda colorida
  à esquerda, tudo centralizado, textos genéricos.
- Pedido aberto: 2–3 direções realmente diferentes lado a lado, numa fileira com título.
- Acessível mesmo sendo mockup: `<button>`, `<a href>`, `<label>` reais, contraste ≥ 4.5:1, alvos ≥ 44 px.
- Protótipo clicável: `<a href="board:Checkout">` liga artboards; marque-os com `interactive: true`.
- Nada sensível no HTML.

## Alterações

- Pedidos que chegam do canvas vêm como `[Ward Design · "Título" (id) · artboard "Nome"] pedido`:
  altere esse artboard. `get_design` também diz qual artboard a pessoa selecionou (`viewing`).
- Antes de editar, garanta que o canvas está aberto para o usuário (seção 1).
- Mudar uma tela: `get_artboard_source(design, name)` → editar → `upsert_artboard` com o **mesmo
  name** (só o `html`, ou só `x`/`y`/`w`/`h`) → print no navegador e avaliação, como na seção 3. Nunca crie um design novo para alterar um
  existente.
- Antes de uma mudança grande, ou ao terminar uma rodada: `save_design_version(design, label)`.
- `delete_artboard` só quando o usuário pedir.
- Só o dono do design (ou um admin) altera; se a ferramenta recusar, explique ao usuário.
