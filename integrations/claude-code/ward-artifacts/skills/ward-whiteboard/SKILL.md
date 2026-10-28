---
name: ward-whiteboard
description: Draw on a Ward Whiteboard (Miro-like board built on Excalidraw at artifacts.wardtecnology.com) through the ward-artifacts MCP server. Use when the user wants post-its, a flowchart, mind map, org chart, retrospective, kanban, user journey, 2×2 matrix, brainstorm or any diagram of boxes and arrows — "whiteboard", "quadro", "miro", "post-it", "fluxograma", "mapa mental", "diagrama", "retro", "kanban" — rather than designed screens (use ward-design for those). Covers opening the board so the user watches, reading it with get_whiteboard, drawing diagrams with automatic layout (create_diagram), adding and editing items (add_elements, update_elements, delete_elements), clustering post-its into frames (arrange_elements), guiding the viewer's camera (show_in_canvas), answering comments and saving versions.
---

# Ward Whiteboard

Um **whiteboard** é um quadro infinito (Excalidraw) onde a pessoa, os convidados dela e você editam ao
vivo: post-its, formas com texto, setas presas aos elementos, frames, textos, imagens e desenho livre.
Use para pensar junto (brainstorm, retro, mapa mental) e para diagramas (fluxos, organogramas,
arquitetura). Telas e mockups visuais continuam na skill `ward-design`.

A pessoa quer **ver você desenhando**: abra o quadro antes de desenhar e deixe aberto.

## Fluxo

1. **Quadro**: `create_whiteboard(title, project, template?)`. Templates: `flowchart`, `retro`, `kanban`,
   `journey`, `matrix`, `mindmap`, `brainstorm`. Quadro que já existe: `list_artifacts(type: whiteboard)`
   ou a URL que a pessoa mandou.
2. **Abrir para a pessoa ver**: em clientes com MCP Apps (chat do Claude, aba App do WardCode) chame
   `open_whiteboard` **uma vez** (mostra o quadro ao vivo no chat, seguindo você). Senão, abra o
   `preview_url` no seu navegador (ex.: Claude Code) e entregue a URL normal para a pessoa.
3. **Ler antes de mexer**: `get_whiteboard` devolve os elementos com `id`, `type` (`sticky` = post-it),
   `text`, `color`, posição, `frame`, setas (`from`/`to`), os frames, quem está no quadro e o que cada um
   seleciona, comentários abertos, votação e um `free_spot` (lugar livre para desenhar). Quadro grande vem
   resumido: peça `frame` ou `region`.
4. **Desenhar**:
   - Diagrama a partir de nós e ligações → `create_diagram` (o servidor calcula o layout):
     `flowchart` (camadas; `direction` TB ou LR), `tree` (para a direita), `org` (de cima para baixo),
     `mindmap` (raiz no centro, ramos dos dois lados), `grid` (post-its em grade). Use `frame` para
     envolver o diagrama num frame com título.
   - Itens soltos → `add_elements`. Dê `id`s seus para ligar setas (`from`/`to`) e frames (`children`)
     no mesmo pedido. Sem `x`/`y` os itens vão lado a lado num lugar livre; `place` posiciona em
     relação a algo (`right_of`, `below`, `inside` um frame, `at`, `grid`).
5. **Mudar**: `update_elements` (texto, cor, posição com `x`/`y` ou `dx`/`dy`, tamanho, frame, setas).
   O texto preso e as setas acompanham. Apagar: `delete_elements` (frame leva o que está dentro).
6. **Organizar**: `arrange_elements` — `cluster` agrupa post-its por tema em frames (você decide os
   grupos e os nomes); também `grid`, alinhar e distribuir.
7. **Mostrar**: `show_in_canvas(design: id do quadro, action: focus|point|fit, element|frame)` leva a
   câmera e o cursor de quem está vendo até o que você quer mostrar.
8. **Versão**: `save_design_version` antes de uma mudança grande ou ao fechar uma etapa.

## Boas práticas

- **Post-it é `sticky`**, não retângulo. Cores: yellow, orange, pink, red, green, teal, blue, purple,
  gray. Uma ideia por post-it, frases curtas.
- **Fluxograma legível**: início/fim em `ellipse`, decisão em `diamond` com setas rotuladas (`sim`/`não`),
  etapas em `rectangle`. Uma direção só (TB ou LR). Até ~15 nós por diagrama; maior que isso, divida
  em frames.
- **Mapa mental**: o primeiro nó é a raiz; 3–7 ramos; cores diferentes por ramo.
- **Retro/brainstorm**: post-its das pessoas são delas. Para agrupar, use `arrange_elements` cluster e
  conte o que fez; não apague nem reescreva o texto de ninguém sem pedir.
- **Não cubra o que existe**: sem posição, o servidor já põe no `free_spot`. Ao posicionar à mão,
  confira `bounds` e `frames` do `get_whiteboard`.
- **Confira o resultado**: depois de desenhar, olhe o quadro no navegador (ou peça `get_whiteboard`) e
  corrija sobreposições e textos cortados.

## Pessoas no quadro

- O dono convida editores em Compartilhar; quem não é editor vê só leitura. Se `get_whiteboard` disser
  `can_edit: false`, você não pode mudar o quadro: diga isso à pessoa.
- **Comentários** são pedidos: aplique a mudança no elemento indicado (`element`) e responda com
  `resolve_comment(design: id do quadro, comment: número, message)`.
- **Votação** e **cronômetro** são da reunião: leia o resultado em `session` do `get_whiteboard`
  (`voting.totals` por elemento) quando a pessoa pedir um resumo.
