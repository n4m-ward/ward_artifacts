# Ward Design: como montar artboards

Um design é um **canvas** com vários **artboards** lado a lado. Cada artboard é **um HTML autocontido**
(abre sozinho no navegador), com CSS e JS inline.

O padrão é **alta fidelidade**: cada tela deve parecer um produto pronto, não um wireframe. A diferença
entre uma tela genérica e uma boa quase nunca é talento: é decidir a direção antes, preencher a tela com
conteúdo de verdade e **olhar o print** de cada tela antes de considerá-la pronta.

## 1. Antes do primeiro artboard: direção

Escreva para você (e mostre ao usuário em 3–4 linhas):

- **Para quem e para quê**: produto, público, plataforma, o que a tela precisa fazer.
- **Direção visual**: um adjetivo forte (editorial, técnico, acolhedor, brutalista, luxuoso…) e uma
  referência concreta ("cardápio de cafeteria de bairro", "painel de corretora", "revista de design").
  Se o usuário tem marca, site, código ou design system, parta dele; inventar do zero é o último recurso.
- **Tipografia**: um par com intenção (display + texto). Evite as escolhas automáticas: Inter, Roboto,
  Arial, Space Grotesk, fontes do sistema. Ex.: Instrument Serif + Hanken Grotesk, Newsreader + Geist,
  Bricolage Grotesque + IBM Plex Sans, DM Serif Display + Manrope, JetBrains Mono para dados.
- **Cor**: 1 fundo, 1–2 tintas de texto, 1 acento e 1 cor de estado, em `oklch()` para ficarem
  harmônicas. Claro ou escuro é uma decisão da direção, não um padrão.

Defina tudo isso uma vez em variáveis CSS no `:root` e **repita o mesmo bloco em todos os artboards**
do design.

## 2. Acabamento

- **Hierarquia**: um elemento dominante por tela. Escala de tipo com contraste real (ex.: 42 / 26 / 15 /
  13 px num celular; 72+ / 32 / 18 numa página). `text-wrap: balance` em títulos.
- **Densidade de produto real**: uma tela de app tem barra de status, navegação, ações e o conteúdo que
  existiria ali. Nada de 3 itens soltos num fundo vazio. Uma landing tem várias seções completas.
- **Espaço**: escala de 4/8 px, margens laterais consistentes (20–24 px no celular), um raio de borda
  (ou dois) usado em tudo.
- **Conteúdo plausível**: nomes, textos, preços, datas e números verossímeis e coerentes entre as telas
  (o produto de R$ 13,50 na Home custa R$ 13,50 no Produto e soma certo na Sacola). Nada de lorem ipsum
  nem de `[PREÇO]`/`[NOME]`, a menos que o usuário peça um wireframe. Só não invente dados sobre coisas
  reais (métricas de uma empresa real, depoimentos de pessoas reais).
- **Imagem**: fotos deixam a tela viva.
  - Use as do usuário quando houver.
  - Fotos do Unsplash por URL (`https://images.unsplash.com/photo-…?w=800&q=70`) só quando você
    conseguir **ver** que a foto carregou e combina; um link inventado vira um buraco.
  - Sem foto confiável: um placeholder honesto (bloco na cor da paleta com um rótulo discreto), nunca um
    gradiente fingindo ser foto nem ilustração SVG improvisada.
- **Ícones**: SVG inline, traço consistente (mesma espessura e estilo em todos). Sem emoji.
- **Evite os vícios de "tela de IA"**: gradiente roxo/azul de fundo, brilho radial no topo, cartão com
  borda colorida só à esquerda, tudo centralizado, todo cartão com a mesma sombra, ícone dentro de
  quadradinho colorido em toda lista, textos genéricos ("Bem-vindo ao futuro").

## 3. Avaliar o print: nenhuma tela está pronta sem você olhar

Toda tela passa por avaliação visual antes de ser considerada pronta. Depois de cada `upsert_artboard`,
**tire um print** da tela no navegador do seu ambiente (no Claude Code, o painel de navegador) com o
canvas aberto pelo `preview_url`, e olhe a imagem:

- Para uma tela: abra o artboard em foco (clique duplo nele, ou o link "abrir sozinho") ou dê zoom na
  área dele. Os iframes do canvas às vezes demoram alguns segundos para pintar na captura: espere e
  capture de novo.
- Para o conjunto: "ajustar tudo" (tecla 1) e um print do canvas inteiro.

Na imagem, procure:

- conteúdo cortado ou escondido (em `fixed`, tudo cabe em `w`×`h`; atenção ao que fica atrás de barras
  fixas);
- texto quebrando onde não devia (preços, botões, abas), sobreposto ou vazando do contêiner;
- fotos que não carregaram, fonte de fallback no lugar da escolhida, ícones desalinhados;
- espaçamento e alinhamento irregulares, contraste fraco, hierarquia confusa;
- inconsistência com os outros artboards (cores, fontes, raios, componentes);
- áreas vazias que fazem a tela parecer inacabada.

Achou algo: corrija com `upsert_artboard` do mesmo `name` e olhe de novo. Repita até a tela estar sem
defeitos; só então passe para a próxima ou diga ao usuário que terminou. No fim, um print do canvas
inteiro para conferir o conjunto. Sem navegador nenhum, revise o HTML contando alturas e larguras e
avise o usuário de que não conseguiu ver a tela.

## Tipos de artboard

| kind | Para quê | Raiz do HTML |
|---|---|---|
| `fixed` | Post, story, tela de app, pôster, banner, mockup | Tamanho exato `w`×`h` em px, `overflow: hidden` |
| `page` | Landing page, site, dashboard, tela web | Fluida: `max-width`, `%`, `rem`; `w` é a largura de referência e `h` a altura nessa largura |

Tamanhos comuns: celular 390×844 · tablet 834×1194 · desktop/página 1440×(altura real) ·
post 1080×1080 · story 1080×1920 · banner 1200×628 · A4 794×1123.

## Regras do formato

1. `<!doctype html>`, `<meta charset="utf-8">`, `<title>` com o nome da tela e `lang` certo.
2. `body { margin: 0 }`. Em `fixed`, o primeiro elemento tem `width`/`height` exatos do artboard.
3. Fontes do Google Fonts (`<link>` do css2). Imagens por URL https, data URI ou SVG inline.
4. Acessível: `<button>`, `<a href>`, `<label>` reais; contraste ≥ 4.5:1; alvos de toque ≥ 44 px em telas
   de app; `alt` nas imagens.
5. Nada sensível no HTML (o dono pode tornar o design público depois).
6. **Ligações entre telas** (protótipo): veja a seção "Protótipo (Play)".

## Protótipo (Play)

No canvas, o botão **▶ Play** abre a tela no tamanho dela (moldura de celular, tablet ou navegador,
conforme `w`×`h` e `kind`) e a pessoa navega clicando, como no aparelho. Ligue as telas no próprio HTML:

| No elemento clicável | O que acontece no Play |
| --- | --- |
| `<a href="board:Checkout">` ou `data-ward-to="Checkout"` | vai para a tela (entra no histórico) |
| `data-ward-overlay="Filtro"` | abre a tela por cima: modal (centralizada) ou folha (largura da tela, sobe de baixo) |
| `data-ward-back` | volta à tela anterior (ou fecha a de cima) |
| `data-ward-close` | fecha a tela de cima |
| `data-ward-transition="slide"`, `"slide-up"`, `"fade"` ou `"none"` | como a troca acontece (padrão: deslizar) |

- Use em `<button>` e `<a>` reais. O valor é o `name` exato do artboard de destino.
- **Toda ação principal leva a algum lugar**: botão de comprar → tela de pagamento; ícone de voltar →
  `data-ward-back`; item da barra de abas → a tela da aba. Crie as telas de destino que faltarem
  (sucesso, erro, vazio, modal de confirmação) em vez de deixar o botão morto.
- Tela que abre por cima (modal, folha, menu) é um artboard próprio do tamanho do painel (ex.: 390×420
  para uma folha num celular de 390), sem fundo escurecido: o Play escurece a tela de baixo.
- Mudança dentro da mesma tela (abas, acordeão, toggle, contador, campo com validação) é JS inline no
  artboard; ele roda no Play.
- As respostas de `upsert_artboard`, `update_layout` e `get_design` listam ligações para telas que não
  existem: corrija antes de entregar.
- Para conferir, abra o Play (botão ▶ na legenda do artboard, ou Enter com ele selecionado) e clique o
  fluxo inteiro. "Áreas clicáveis" (tecla H) destaca o que tem ligação.

## Tokens, componentes e páginas

- **Tokens** (`set_design_tokens`): defina a direção uma vez como variáveis CSS (`--paper`, `--ink`,
  `--accent`, `--display`, `--text`, `--radius`) e use `var(--nome)` em todos os artboards. O bloco entra
  no `:root` de cada tela; a pessoa ajusta no painel Tema e todas as telas mudam juntas. Não redefina
  esses nomes no `:root` do artboard.
- **Componentes**: uma peça repetida (barra de navegação, rodapé, card) vira um artboard próprio
  (ex.: `Navbar`, só a peça, no tamanho dela) e entra nas telas com
  `<ward-include name="Navbar"></ward-include>`. O `<style>` e as fontes do componente vêm junto.
  Mudou o componente, mudam todas as telas que o usam.
- **Páginas**: muitos artboards? Separe por fluxo ou por tipo com `update_layout.pages`
  (`{"key": "checkout", "name": "Checkout"}`) e `page` em cada artboard.

## Tweaks (controles para a pessoa ajustar sem pedir)

Declare poucos controles que valham a pena (uma cor de destaque, um raio, modo escuro, densidade),
nunca textos. A pessoa ajusta no painel Tweaks do canvas e o valor fica salvo no próprio artboard:

```html
<script type="application/json" id="ward-tweaks">
{"accent": {"type": "color", "label": "Destaque", "value": "#c2410c"},
 "radius": {"type": "range", "label": "Raio", "min": 0, "max": 32, "unit": "px", "value": 16},
 "dark": {"type": "boolean", "label": "Fundo escuro", "class": "is-dark", "value": false},
 "density": {"type": "enum", "label": "Densidade", "options": ["compacta", "normal"], "value": "normal"}}
</script>
```

- `color`, `range` e `text` viram variáveis CSS (`--accent`, `--radius` com a unidade): use-as no CSS.
- `boolean` com `class` liga essa classe no `<html>` (`.is-dark .card {…}`).
- `enum` vira `data-density="compacta"` no `<html>`.
- Repita o mesmo valor padrão no `:root` do CSS, para o arquivo abrir certo fora do canvas.

## Edição pela pessoa

A pessoa pode mover, redimensionar e editar elementos à mão no canvas (texto, cores, espaçamento…).
Antes de mudar uma tela, leia o HTML atual com `get_artboard_source`: ele pode ter mudado.

## Organização no canvas

- Coordenadas em px no canvas. Deixe **80 px** entre artboards de uma fileira e **160 px** entre fileiras.
- Sem `x`/`y`, um artboard novo entra à direita do último da primeira fileira da página (ou numa fileira
  nova abaixo, se ali já houver outro).
- **Nunca deixe um artboard em cima de outro** (nem de uma nota): um esconde o outro. As respostas de
  `upsert_artboard`, `update_layout` e `get_design` avisam artboards sobrepostos; corrija com
  `update_layout` na hora. Ao passar `x`/`y` ou mudar `w`/`h`, confira o espaço livre.
- Fluxo de protótipo: telas na ordem do caminho, da esquerda para a direita; a pessoa liga "Ligações" no
  canvas para ver as setas entre elas.
- Use notas `kind: "title"` acima de uma fileira (ex.: `y` = topo da fileira − 220) para nomear grupos,
  e `kind: "sticky"` para observações curtas.
- Nomes de artboard curtos, estáveis e só ASCII (`Home`, `Cardapio`, `Checkout`); o rótulo com acento vai
  em `title`. É pelo nome que você edita depois e que os links `board:` encontram a tela.
- Variações lado a lado: quando o pedido é aberto, 2–3 direções realmente diferentes (não só cores
  trocadas), numa fileira com um título.

## Fluxo

1. `create_design` → guarde o `id` e **abra o canvas antes de criar telas**: `open_design` em clientes com
   MCP Apps (o canvas aparece no chat); nos outros, abra o `preview_url` no seu navegador e passe a `url`
   ao usuário. O canvas atualiza sozinho a cada mudança, então a pessoa acompanha você trabalhando.
2. Direção (seção 1), depois para cada tela: `upsert_artboard` → print no navegador → corrigir até
   ficar sem defeitos (seção 3).
3. `update_layout` para organizar fileiras, títulos e notas, e um print do canvas inteiro: nada
   sobreposto, nada cortado, títulos sem cobrir telas.
4. Protótipo: ligações completas (seção "Protótipo (Play)") e um teste no Play clicando o fluxo.
5. Para mudar uma tela: `get_artboard_source` → editar → `upsert_artboard` com o mesmo `name`.
6. `save_design_version` quando chegar num ponto que valha guardar.
7. Entregue ao usuário a `url` do design. O `preview_url` (uso único, sem login) é para você.
