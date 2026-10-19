---
name: ward-design
description: Design screens and visuals on a Ward Design canvas (artifacts.wardtecnology.com) through the ward-artifacts MCP server. Use when the user wants mockups, app screens, UI flows, landing page or website layouts, social posts, banners, posters, or several visual variations side by side — "design", "mockup", "tela", "protótipo", "post", "layout", "variações", "Ward Design" — rather than a single standalone page. Covers opening the live canvas before editing so the user watches the work, setting a design direction, high-fidelity craft, reviewing a screenshot of every artboard for both defects and design sense (purpose, hierarchy, typography, color, UX and usability) in the embedded browser before calling it done, clickable prototypes tested in Play mode with real clicks through show_in_canvas (links between screens, overlays, back, transitions), guiding the viewer's camera and AI cursor while working, keeping artboards from overlapping on the canvas, editing an artboard, and saving versions.
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
     próprio chat. Chame **uma vez** por conversa: esse canvas segue você (câmera e cursor) e as telas
     novas aparecem nele sozinhas. Não chame `open_design` de novo a cada tela (polui o chat); só se a
     pessoa pedir ou se o canvas anterior tiver ficado muito para trás na conversa.
   - Sem navegador nenhum: passe a `url` ao usuário e diga que ela atualiza sozinha.
3. Diga ao usuário que o canvas está aberto e que as telas vão aparecendo ali.

## Design system primeiro

Antes de definir uma direção do zero, veja se já existe um design system (um por projeto, visíveis a
todos): `list_design_systems` e `get_design_system("usuario/projeto")`.
- Existe: use o tema dele (os tokens entram sozinhos ao importar) e reaproveite componentes com
  `import_component` (`link` muda junto com o original; `copy` cria uma versão adaptada) ou
  `<ward-include ds="usuario/projeto" name="Botao"></ward-include>` dentro de uma tela.
- Não existe e o projeto vai ter várias telas: crie com `save_design_system` (título, descrição,
  documentação em markdown), defina o tema com `set_design_tokens` e cada componente como artboard
  (`upsert_artboard` com `description` e `tags`), sem fundo próprio. Promova peças boas de um design
  com `publish_component`.
- Componentes de design system são editados como qualquer design (mesmas ferramentas, com o id ou
  "usuario/projeto"). Só o dono do projeto (ou um admin) altera.

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
   - Uma tela: use zoom na área dele, ou o ▶ Play na legenda do artboard (abre no tamanho do aparelho).
   - O conjunto: "ajustar tudo" (tecla 1) e print do canvas inteiro.
   - Os iframes do canvas às vezes demoram alguns segundos para pintar na captura: espere e capture de
     novo antes de concluir que a tela está em branco.
3. **Leia o aviso da resposta** de `upsert_artboard`/`update_layout`: "artboards sobrepostos" exige
   `update_layout` na hora (um artboard nunca fica em cima de outro, nem de um título ou nota).
4. Na imagem, procure: artboards, títulos ou notas cobrindo uns aos outros, conteúdo cortado ou atrás de barras fixas, texto quebrando ou vazando, fotos que
   não carregaram, fonte de fallback, alinhamento e espaçamento irregulares, contraste fraco, hierarquia
   confusa, inconsistência com as outras telas, áreas vazias com cara de inacabado.
5. Faça a **revisão de design** (seção abaixo): proposta, hierarquia, tipografia, cor e usabilidade.
   Sem defeito técnico não basta: a tela tem que fazer sentido.
6. Achou algo: corrija com `upsert_artboard` do mesmo `name` e tire o print de novo. Repita até a tela
   estar sem defeitos e passar na revisão; só então siga para a próxima.
7. Com todas as telas prontas: `update_layout` para organizar (fileiras com 80 px entre artboards, 160 px
   entre fileiras, títulos `kind: "title"` e notas adesivas) e um print do canvas inteiro para avaliar o
   conjunto: nada sobreposto, títulos sem cobrir telas. `get_design` também lista sobreposições.
8. Fluxo com várias telas: ligações completas e teste no Play (seção 4).
9. No fim, passe ao usuário a **URL do design** (`url`), nunca o `preview_url`. Diga que avaliou os
   prints, o que validou na revisão de design e o que corrigiu. Se não teve como ver as telas, diga isso.

## Mostrar o que você está fazendo (`show_in_canvas`)

Quem acompanha o canvas (o MCP App no chat, a aba App do WardCode, o canvas no navegador) **segue
você**: a câmera voa até a tela e um cursor com o seu nome aponta onde você está. Isso já acontece
sozinho em `upsert_artboard` (foca a tela salva), `get_artboard_source` ("Lendo …") e `update_layout`.
Use `show_in_canvas` para conduzir o resto, sempre com uma `message` curta em português:

- `focus` numa tela antes de falar dela ou de revisá-la; `fit` para mostrar o conjunto no fim.
- `point` num elemento (texto visível ou seletor CSS) para mostrar o que vai mudar ou o que mudou.
- `play` abre o Play numa tela; `click` clica num elemento dentro do Play; `back` e `close`.
- A pessoa pode pausar o "seguir"; não brigue com isso (não repita ações só para puxar a câmera).

## Revisão de design: fazer sentido, não só estar pronto

Uma tela sem defeito técnico ainda pode estar errada. A cada print, **olhe a imagem como diretor de arte
e como pessoa usuária** e responda por escrito, para você mesmo, antes de seguir:

**Proposta**
- Qual é o objetivo desta tela e quem a usa? Em 3 segundos dá para saber onde se está e o que fazer?
- A tela segue a direção que você definiu (adjetivo, referência, paleta, fontes)? Combina com as
  outras telas, como se fosse o mesmo produto?

**Hierarquia e composição**
- Há **um** ponto focal e uma ação principal evidente (uma só por tela, as outras visivelmente
  secundárias). O olho percorre na ordem certa: título → conteúdo → ação.
- Tudo alinhado a uma grade; espaçamento numa escala só (4/8 px) e consistente; o que é relacionado
  fica junto e o que é diferente fica separado (proximidade). Respiro suficiente, sem tela apertada
  nem vazia demais.
- Equilíbrio de peso visual: nada gritando sem motivo, nada importante sumindo.

**Tipografia**
- No máximo 2 famílias, com papéis claros (título e texto). Escala com contraste real entre níveis
  (ex.: razão 1.25–1.333), poucos tamanhos e pesos, usados de forma consistente.
- Texto corrido com 45–75 caracteres por linha e altura de linha 1.4–1.6; títulos 1.1–1.25. Sem
  palavra sozinha na última linha de um título, sem caixa-alta em blocos longos.

**Cor**
- Uma cor de destaque reservada para ação e estado; neutros fazendo o resto (algo como 60/30/10).
  Cores com significado (sucesso, erro, aviso) sempre iguais em todas as telas.
- Contraste: texto ≥ 4.5:1 (≥ 3:1 em texto grande e em bordas de controles). Informação nunca só pela cor.

**UX e usabilidade**
- Linguagem da pessoa, não do sistema; rótulos de botão dizem a ação ("Pagar R$ 48,90", não "OK").
- Estado visível: onde estou (navegação marcada), o que está carregando, o que deu certo ou errado.
- Controle e saída: voltar, cancelar e fechar sempre à mão; ação destrutiva pede confirmação.
- Consistência: o mesmo componente tem a mesma aparência e o mesmo lugar em todas as telas.
- Reconhecer em vez de lembrar: opções visíveis, campos com `label`, exemplos e valores padrão.
- Estados que existiriam de verdade: vazio, carregando, erro, desabilitado, selecionado.
- Toque e alcance: alvos ≥ 44 px com espaço entre eles; no celular, a ação principal ao alcance do
  polegar; nada importante escondido atrás de barras fixas.

**Decida e corrija**
- Anote o que funciona e o que não funciona. Se algo está só "ok", melhore: a meta é a tela que um bom
  designer de produto entregaria, não a primeira versão que renderizou.
- Corrija com `upsert_artboard`, tire o print de novo e repita a revisão. Só siga quando as respostas
  forem todas sim.
- Na entrega, diga ao usuário em poucas linhas o que validou (proposta, hierarquia, tipografia, cor,
  usabilidade) e o que mudou por causa disso.

## 4. Protótipo (Play)

Telas de um mesmo fluxo (app, site, checkout, onboarding) são um **protótipo**: a pessoa aperta ▶ Play e
navega clicando, com a tela no tamanho do aparelho (moldura de celular, tablet ou navegador). Ligue as
telas no HTML de cada uma (detalhes em `get_design_guide`, seção "Protótipo (Play)"):

- `<a href="board:Checkout">` ou `data-ward-to="Checkout"`: vai para a tela.
- `data-ward-overlay="Filtro"`: abre a tela por cima (modal ou folha). A tela de cima é um artboard do
  tamanho do painel, sem fundo escurecido.
- `data-ward-back`: volta. `data-ward-close`: fecha a de cima.
- `data-ward-transition="slide" | "slide-up" | "fade" | "none"`.

Regras:
1. **Toda ação principal leva a algum lugar.** Crie as telas de destino que faltarem (sucesso, erro,
   confirmação, modal) em vez de deixar botão morto. Ícone de voltar usa `data-ward-back`.
2. Mudança dentro da mesma tela (abas, acordeão, toggle, validação) é JS inline no artboard.
3. As respostas de `upsert_artboard`, `update_layout` e `get_design` listam **ligações quebradas**
   (para telas que não existem): corrija antes de entregar.
4. **Teste o fluxo de verdade** quando o design tiver interação que faça sentido testar (navegação,
   menu, modal, abas, formulário). Post, banner ou tela solta sem ação não precisa.
   - Use `show_in_canvas` com `click` (`board` = a tela onde o elemento está, `element` = texto
     visível ou seletor CSS, `message` = o que está testando). O clique acontece no Play de quem está
     vendo, que assiste ao teste, e a resposta diz **em que tela o Play ficou**, o que abriu por cima e
     se houve erro de script. Funciona também no chat do Claude, sem navegador.
   - Percorra o caminho principal: ida, volta (`data-ward-back`), telas por cima e fechar. Compare cada
     resultado com o esperado ("Pagar" devia levar a "Fim"); errado, corrija e teste de novo.
   - Resposta "ninguém está com o canvas aberto": nada foi clicado. Abra o canvas (`open_design`, ou o
     `preview_url` no seu navegador) e repita. Sem resultado de clique, **não diga que validou**.
   - Depois de testar, `show_in_canvas` com `close` e conte ao usuário o caminho testado e o resultado.
   - No navegador embutido, clique dentro da tela com o Play em **100%** (tamanho real): com a tela
     reduzida para caber, a automação às vezes não consegue clicar dentro dela.

## Qualidade (resumo do guia)

- Densidade de produto real: barra de status, navegação, ações e o conteúdo que existiria na tela.
- Conteúdo plausível e coerente entre as telas: nomes, preços e números verossímeis. Nada de lorem
  ipsum nem `[PREÇO]`, a menos que o usuário peça wireframe.
- Fotos: as do usuário, ou do Unsplash por URL **depois de ver que carregaram e combinam**. Sem foto
  confiável, um placeholder honesto na paleta; nunca um gradiente fingindo ser foto.
- Imagens do design: as que a pessoa envia no canvas aparecem no `get_design` (use `assets/{nome}` no
  HTML). Para guardar uma imagem sua no design (logo, foto baixada), `add_design_asset` com base64.
- Tweaks: declare 2–4 controles úteis (cor de destaque, raio, modo escuro) no bloco `ward-tweaks`
  (formato no guia); a pessoa ajusta no canvas sem pedir.
- Tipografia com intenção (não Inter, Roboto, Arial, Space Grotesk nem fonte do sistema por padrão),
  escala com contraste real, ícones SVG com traço consistente, sem emoji.
- Fuja dos vícios de "tela de IA": gradiente roxo/azul, brilho radial no topo, cartão com borda colorida
  à esquerda, tudo centralizado, textos genéricos.
- Pedido aberto: 2–3 direções realmente diferentes lado a lado, numa fileira com título.
- Acessível mesmo sendo mockup: `<button>`, `<a href>`, `<label>` reais, contraste ≥ 4.5:1, alvos ≥ 44 px.
- Fluxo de várias telas: protótipo ligado e testado no Play (seção 4).
- Nada sensível no HTML.

## Alterações

- Pedidos que chegam do canvas vêm como `[Ward Design · "Título" (id) · artboard "Nome"] pedido`:
  altere esse artboard.
- `get_design` mostra o que a pessoa está vendo (`viewing`): artboard e **elemento** selecionados
  (tag, texto e caminho a partir do `<body>`, ex.: `0/1`), artboards visíveis e **erros de script**.
  "Isto", "este título", "a tela da esquerda": resolva por aí antes de perguntar.
- **Comentários** do canvas (`comments` no `get_design`, numerados #1, #2...) são pedidos ancorados
  num elemento ou artboard. Para cada um: aplique a mudança, confira o print e responda com
  `resolve_comment(design, comment, message)` em uma frase. Pergunta ou dúvida: responda com
  `resolve: false`. Erro de script listado: corrija o artboard e confira de novo.
- Antes de editar, garanta que o canvas está aberto para o usuário (seção 1).
- Mudar uma tela: `get_artboard_source(design, name)` → editar → `upsert_artboard` com o **mesmo
  name** (só o `html`, ou só `x`/`y`/`w`/`h`) → print no navegador e avaliação, como na seção 3. Nunca crie um design novo para alterar um
  existente.
- Antes de uma mudança grande, ou ao terminar uma rodada: `save_design_version(design, label)`.
- `delete_artboard` só quando o usuário pedir.
- Só o dono do design (ou um admin) altera; se a ferramenta recusar, explique ao usuário.
