---
name: ward-design
description: Design screens and visuals on a Ward Design canvas (artifacts.wardtecnology.com) through the ward-artifacts MCP server. Use when the user wants mockups, app screens, UI flows, landing page or website layouts, social posts, banners, posters, or several visual variations side by side — "design", "mockup", "tela", "protótipo", "post", "layout", "variações", "Ward Design" — rather than a single standalone page. Covers create_design, the artboard HTML format (fixed vs page), placing and naming artboards, notes, clickable prototypes with board: links, editing an artboard, and saving versions.
---

# Ward Design

Um **design** é um canvas com vários **artboards** lado a lado. Cada artboard é um HTML autocontido
(uma tela, um post, uma página). Use um design quando houver **várias telas ou variações**; para uma
única página use a skill `ward-artifact-create`.

## Fluxo

1. `create_design(title, project)`: `project` é a feature da conversa (veja `list_projects`). Guarde o `id`.
2. `get_design_guide` **antes do primeiro artboard**: regras do formato e dois esqueletos de HTML.
3. Um `upsert_artboard` por tela, com `name` curto e estável, `kind`, `w`, `h` e o `html` completo.
   - `kind: "fixed"`: post, story, tela de app, pôster, ou seja, tamanho exato (390×844, 1080×1080…).
   - `kind: "page"`: site, landing, dashboard, ou seja, raiz fluida; `w` 1280–1440 e `h` a altura real.
4. `update_layout` para organizar: fileiras com 80 px entre artboards e 160 px entre fileiras, títulos
   (`kind: "title"`) acima de cada fileira e notas adesivas para observações.
5. Mostre ao usuário a **URL do design** (a resposta traz `url`). Se você tiver navegador, abra o
   `preview_url` (uso único, sem login) para conferir.

## Qualidade

- Uma identidade por design: defina cores e fontes uma vez (variáveis CSS) e repita em todos os
  artboards. 1–3 famílias de fonte, paleta pequena, nada de gradiente genérico ou emoji.
- Sem lorem ipsum nem números inventados: placeholders como `[PREÇO]`.
- Acessível mesmo sendo mockup: `<button>`, `<a href>`, `<label>` reais, contraste ≥ 4.5:1, alvos ≥ 44 px.
- Protótipo clicável: `<a href="board:Checkout">` liga artboards; marque-os com `interactive: true`.
- Nada sensível no HTML.

## Alterações

- Mudar uma tela: `get_artboard_source(design, name)` → editar → `upsert_artboard` com o **mesmo
  name** (só o `html`, ou só `x`/`y`/`w`/`h`). Nunca crie um design novo para alterar um existente.
- Antes de uma mudança grande, ou ao terminar uma rodada: `save_design_version(design, label)`.
- `delete_artboard` só quando o usuário pedir.
- Só o dono do design (ou um admin) altera; se a ferramenta recusar, explique ao usuário.
