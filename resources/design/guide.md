# Ward Design: como montar artboards

Um design é um **canvas** com vários **artboards** lado a lado. Cada artboard é **um HTML autocontido**
(abre sozinho no navegador), com CSS e JS inline e imagens em data URI ou SVG inline.

## Tipos de artboard

| kind | Para quê | Raiz do HTML |
|---|---|---|
| `fixed` | Post, story, tela de app, pôster, banner, mockup | Tamanho exato `w`×`h` em px, `overflow: hidden` |
| `page` | Landing page, site, dashboard, tela web | Fluida: `max-width`, `%`, `rem`; `w` é a largura de referência e `h` a altura nessa largura |

Tamanhos comuns: celular 390×844 · tablet 834×1194 · desktop/página 1440×(altura real) ·
post 1080×1080 · story 1080×1920 · banner 1200×628 · A4 794×1123.

## Regras

1. `<!doctype html>`, `<meta charset="utf-8">`, `<title>` com o nome da tela e `lang` certo.
2. `body { margin: 0 }`. Em `fixed`, o primeiro elemento tem `width`/`height` exatos do artboard.
3. Fontes: Google Fonts (`<link>` do css2) ou fontes do sistema. Evite Inter, Roboto e Arial como escolha
   padrão; escolha 1–3 famílias com intenção.
4. Paleta pequena e consistente entre os artboards do mesmo design (defina variáveis CSS no `:root`).
5. Sem lorem ipsum nem números inventados: use placeholders como `[PREÇO]`.
6. Acessível: `<button>`, `<a href>`, `<label>` reais; contraste ≥ 4.5:1; alvos de toque ≥ 44 px em telas
   de app; ícones em SVG inline (sem emoji).
7. Nada sensível no HTML (o dono pode tornar o design público depois).
8. **Links entre artboards** (protótipo): `<a href="board:NomeDoArtboard">` e marque os artboards com
   `interactive: true`.

## Organização no canvas

- Coordenadas em px no canvas. Deixe **80 px** entre artboards de uma fileira e **160 px** entre fileiras.
- Sem `x`/`y`, um artboard novo entra à direita do último da primeira fileira.
- Use notas `kind: "title"` acima de uma fileira (ex.: `y` = topo da fileira − 220) para nomear grupos,
  e `kind: "sticky"` para observações curtas.
- Nomes de artboard curtos e estáveis (`Home`, `Checkout`, `Post`): é por eles que você edita depois.

## Fluxo

1. `create_design` → guarde o `id`.
2. Um `upsert_artboard` por tela (HTML completo em `html`).
3. `update_layout` para organizar fileiras, títulos e notas.
4. Para mudar uma tela: `get_artboard_source` → editar → `upsert_artboard` com o mesmo `name`.
5. `save_design_version` quando chegar num ponto que valha guardar.
6. Entregue ao usuário a `url` do design. O `preview_url` (uso único, sem login) é para você conferir no
   seu navegador.
