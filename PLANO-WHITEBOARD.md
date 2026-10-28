# Ward Whiteboard — Levantamento

Um quadro branco no estilo Miro dentro do Ward Artifacts: post-its, formas, linhas que ligam os
elementos, frames, texto, caneta e imagens num canvas infinito, para fazer diagramas, fluxos,
retrospectivas e brainstorms. A pessoa edita à mão, várias pessoas editam juntas, e a IA lê e desenha
no quadro pelo MCP.

Este documento é o levantamento antes de implementar. Nada daqui foi implementado ainda.

## Decisões tomadas (28/10/2026)

- **Feature própria do Ward Artifacts**, separada do Ward Design, com **aba própria** no menu
  ("Whiteboards"). Não mistura conceitos com o design. A mesma infraestrutura do design é reaproveitada
  para economizar trabalho.
- **Motor: Excalidraw** (`@excalidraw/excalidraw`, MIT), com ferramentas MCP nossas para a IA criar e
  editar os quadros.
- **Ao vivo por consulta curta**, no mesmo padrão do canvas de design. Sem WebSocket.
- **Notas do design** (`design_notes`) ficam como estão: o whiteboard tem o próprio formato e não precisa
  migrar nada.
- **Quem edita**: o dono e as pessoas que ele convida como editoras (ver Permissões).
- **Ferramentas no design**: seguir a proposta da seção abaixo (artboard dentro do quadro; camada
  Excalidraw no design só se fizer falta).
- **Ordem das fases**: decidir mais perto de começar (há uma sugestão no fim).

## Excalidraw: o que a biblioteca dá pronto

Pacote `@excalidraw/excalidraw` 0.18.1, MIT, componente React (aceita React 17, 18 ou 19).

| Já vem pronto | Observação |
|---|---|
| Canvas infinito com pan/zoom, grade, seleção, mover, redimensionar, girar, agrupar, travar, alinhar, distribuir, ordem, copiar/colar, desfazer/refazer, atalhos | Interface completa, em pt-BR (`langCode="pt-BR"`) |
| Retângulo, losango, elipse, seta, linha, desenho livre, texto, imagem, frame, borracha, laser | Formas com texto dentro (rótulo preso ao contêiner) |
| Setas presas aos elementos (acompanham ao mover), pontas variadas, seta em cotovelo, rótulo na seta | É o "linhas que ligam vários post-its" |
| Estilo desenhado à mão ou limpo | `roughness: 0` e fonte Nunito deixam com cara de diagrama "limpo" |
| Bibliotecas de formas (pacotes de ícones/componentes reutilizáveis) | Dá para ter uma biblioteca Ward (fluxograma, post-its, etc.) |
| Exportar PNG, SVG, área de transferência, JSON (`exportToBlob`, `exportToSvg`, `serializeAsJSON`) | |
| Elementos a partir de um esqueleto simples (`convertToExcalidrawElements`): forma + `label`, seta com `start`/`end` apontando para ids | Útil para o que a pessoa cola; a IA usa o servidor (ver abaixo) |
| Juntar mudanças de várias pessoas (`reconcileElements`, cada elemento tem `version` e `versionNonce`) | É o que o excalidraw.com usa na colaboração |
| Cursores e seleção de outras pessoas (`collaborators` no `updateScene`, `onPointerUpdate` com o nosso cursor) | Os cursores aparecem sem desenharmos nada |
| Iframes no quadro (`embeddable`, com lista de domínios permitidos) | Um artboard do Ward Design pode aparecer dentro do quadro |
| Mermaid → Excalidraw (`@excalidraw/mermaid-to-excalidraw` 3.1.0, MIT, roda no navegador) | "Colar Mermaid" para a pessoa |

O que **não** vem pronto:

- **Post-it de verdade**: a 0.18.1 não tem tipo `stickynote` (a documentação nova já cita, mas ainda não
  está na versão publicada). Um post-it vira um retângulo com fundo colorido, sem borda e com texto
  dentro, e o atalho "N" e uma ferramenta "Post-it" na barra criam esse retângulo com estilo pronto.
- **O "+" que cria um post-it já ligado** (o atalho mais usado no Miro): o Excalidraw tem algo parecido
  (Ctrl/Cmd + seta cria uma forma conectada), mas não o botão. Fazemos se fizer falta.
- **Persistência, permissões, ao vivo, presença com nome, comentários, IA**: são nossos.
- Votação, cronômetro, cards de kanban, mapa mental com layout automático: não existem; ficam para o fim.

## O que reaproveitamos do Ward Artifacts / Ward Design

| Peça existente | Uso no whiteboard |
|---|---|
| Artifact com `type` (`page`/`design`), URL `/{usuario}/{projeto}/{titulo}-{uuid}`, projeto, dono, visibilidade, link público temporário, `preview_url` | Novo `type = whiteboard`, igual em tudo isso |
| Ao vivo por consulta curta (`/d/{uuid}/live?rev=N`, 204 quando nada mudou, 1,5 s visível / 10 s oculta) | Mesmo padrão em `/w/{uuid}/live`, devolvendo só os elementos que mudaram |
| Edição em lote (`POST /d/{uuid}/edit`) | `POST /w/{uuid}/elements` com os elementos alterados |
| Presença da IA (`DesignPresence`, `show_in_canvas`: câmera, cursor, seguir) | Mesma ideia; o Excalidraw desenha os cursores |
| Comentários com respostas e imagens (`design_comments`) | Comentários ancorados num elemento do quadro (fase posterior) |
| Imagens (`design_assets`, servidas com token) | Arquivos das imagens do Excalidraw (`files`) guardados como assets |
| Versões (`design_snapshots`, zip com os 10 últimos) | Versões do quadro (JSON `.excalidraw`) |
| MCP App que abre o canvas no chat | Abrir o quadro no Claude e no Ward Code (fase posterior, ver Riscos) |
| OAuth, `WardTool`, regras de acesso por `ArtifactPolicy` | Mesmas |

## Ferramentas do whiteboard também no design?

A ideia de ter "as mesmas ferramentas" no canvas de design faz sentido como conceito, mas com o
Excalidraw o custo muda: ele tem a própria câmera e o próprio desenho, e o canvas de design tem outra,
com artboards em iframes. Colocar um Excalidraw por cima dos artboards exige sincronizar as duas câmeras
a cada movimento e decidir quem recebe cada clique. É possível, mas é trabalho grande e frágil.

**Proposta (aprovada):**

1. Agora: o design continua com as notas e setas que já tem (títulos, post-its, setas de protótipo).
2. No whiteboard: um artboard do design pode ser **colocado dentro do quadro** (elemento `embeddable`
   apontando para o artboard). Assim dá para desenhar fluxos e anotar em volta das telas, no quadro.
3. Depois, se fizer falta, avaliar o Excalidraw como camada no design.

## Recursos (paridade com o Miro)

**MVP** entra na primeira entrega usável; **V2** logo depois; **Depois** fica para o fim.

| Recurso | Como | Prioridade |
|---|---|---|
| Post-its coloridos | Ferramenta nossa que cria retângulo com estilo de post-it (paleta de cores Ward) | MVP |
| Formas, texto, setas presas, frames, desenho livre, imagens | Excalidraw | MVP |
| Diagramas limpos (sem efeito à mão) por padrão | `roughness: 0`, fonte Nunito como padrão do quadro | MVP |
| Salvar sozinho enquanto edita | `onChange` com espera curta → envia os elementos mudados | MVP |
| Várias pessoas editando juntas | Ao vivo + `reconcileElements` | MVP |
| Ver quem está e o cursor de cada um | `collaborators` + presença nossa | MVP |
| A IA lê e desenha no quadro | Ferramentas MCP (ver abaixo) | MVP |
| Lista de quadros (aba "Whiteboards") e criar quadro | Página nova no menu | MVP |
| Exportar PNG/SVG/JSON | Excalidraw (já no menu dele) | MVP |
| Versões | Reaproveita snapshots | V2 |
| Biblioteca Ward de formas (fluxograma, post-its, ícones) | Biblioteca do Excalidraw | V2 |
| Templates (fluxograma, retrospectiva, kanban, jornada, matriz 2×2) | JSON prontos + `create_whiteboard(template)` | V2 |
| Colar Mermaid | `mermaid-to-excalidraw` | V2 |
| Artboard do design dentro do quadro | `embeddable` com o host de conteúdo permitido | V2 |
| Comentários ancorados em elementos | Reaproveita `design_comments` | V2 |
| Seguir alguém (câmera acompanha) | Presença nossa + `scrollToContent` | V2 |
| Quadro no MCP App (chat) | Ver Riscos | Depois |
| Votação, cronômetro, apresentação por frames, mapa mental, cards | Nosso | Depois |

## Arquitetura

### Página e aba

- Menu ganha **Whiteboards** (`/whiteboards`): lista dos quadros (meus e compartilhados), busca, criar.
- O quadro abre na URL de artifact de sempre; a página carrega o pacote do whiteboard só quando
  `type = whiteboard`.
- Barra superior nossa (título, projeto, visibilidade/Compartilhar, quem está online, versões) por cima
  do Excalidraw; o resto da interface é a dele, com o menu principal enxuto (`MainMenu`) e sem os itens
  que não usamos (abrir arquivo local, colaboração do excalidraw.com).

### Front-end

- O projeto não usa React hoje. Entra **React + `@excalidraw/excalidraw`** numa **entrada nova do Vite**
  (`resources/js/whiteboard/main.jsx`), carregada só na página do quadro. O CI já compila os assets para
  a branch `deploy`, então nada muda no deploy.
- As fontes do Excalidraw ficam no nosso servidor (`EXCALIDRAW_ASSET_PATH` apontando para os arquivos
  copiados em `public/build`), sem depender de CDN de terceiros.
- Módulos: `board.jsx` (monta o Excalidraw), `sync.js` (envio e consulta curta), `presence.js`,
  `sticky.js` (ferramenta de post-it), `files.js` (imagens ↔ assets).

### Modelo de dados

O Excalidraw guarda o quadro como uma lista de elementos JSON, cada um com `id`, `version`,
`versionNonce` e `isDeleted`. Guardamos **uma linha por elemento**, para que duas pessoas mexendo em
elementos diferentes nunca se atrapalhem:

```
artifacts             type: page | design | whiteboard (novo valor), + whiteboard_seq (relógio do quadro)
whiteboard_elements   id, artifact_id, element_id (id do Excalidraw), version, version_nonce,
                      is_deleted, data JSON (o elemento inteiro), seq (whiteboard_seq quando gravou),
                      updated_by, timestamps
                      único (artifact_id, element_id); índice (artifact_id, seq)
whiteboard_files      artifact_id, file_id (id do Excalidraw), asset (nome em design_assets ou tabela própria),
                      mime, timestamps
artifacts.whiteboard_state JSON  (cor de fundo, grade, padrões do quadro; não a câmera de cada um)
```

- Elementos apagados ficam com `is_deleted = true` (é assim que o Excalidraw sincroniza apagar);
  uma limpeza periódica remove os apagados há mais de alguns dias.
- Versões: um snapshot é o JSON `.excalidraw` do quadro inteiro, como os zips do design.
- Limites iniciais: 10.000 elementos por quadro, 2 MB por envio, imagens até o limite atual de assets.

### Ao vivo (consulta curta)

Mesmo padrão do design, mas por diferença:

- `GET /w/{uuid}/live?since=SEQ&p=P` → `204` se nada mudou; senão os elementos com `seq > SEQ`, o
  `seq` novo e a presença das outras pessoas e da IA.
- `POST /w/{uuid}/elements` com os elementos que o `onChange` mudou (só os que têm `version` maior que a
  última enviada). O servidor grava um elemento só se a `version` dele for maior que a guardada (empate:
  vence o `versionNonce` menor, como no Excalidraw), soma 1 no `whiteboard_seq` e devolve o `seq`.
- O cliente junta o que chegou com `reconcileElements` e aplica com
  `updateScene({ elements, captureUpdate: CaptureUpdateAction.NEVER })`, para a mudança dos outros não
  entrar no desfazer de quem está editando.
- Envio com espera curta (~300 ms depois da última mudança; durante um arraste, no máximo a cada 500 ms).
- Intervalo da consulta: 1,5 s com a aba visível (pode baixar para ~700 ms quando há outra pessoa
  editando), 10 s com a aba oculta.

### Presença

- `POST /w/{uuid}/presence` com cursor (`onPointerUpdate`), seleção e nome, no máximo a cada ~500 ms
  quando muda; guardado no cache com validade de ~15 s.
- O `live` devolve a presença dos outros → `updateScene({ collaborators })`. O Excalidraw desenha os
  cursores com nome e cor e o contorno do que cada um selecionou.
- Com consulta curta o cursor dos outros anda "aos saltos" (a cada 1–1,5 s). Aceitável para o começo.
- A IA aparece como mais um colaborador (nome "IA", cor própria) quando cria ou aponta algo.

### A IA desenhando: conversão no servidor

As ferramentas MCP rodam no PHP, e o `convertToExcalidrawElements` só roda no navegador. Se a conversão
dependesse de alguém com o quadro aberto, a IA não conseguiria desenhar num quadro fechado. Por isso:

- O servidor tem um **conversor PHP** (`App\Whiteboards\ElementFactory`) que recebe o formato simples
  da IA e gera **elementos Excalidraw completos**: retângulo/elipse/losango/post-it com texto dentro
  (contêiner + texto com `containerId`), seta com `startBinding`/`endBinding` e `boundElements` nos dois
  lados, frame com `frameId` nos filhos, linha, texto.
- A largura do texto é estimada no servidor. Quando o quadro abre, o cliente passa os elementos por
  `restoreElements` (que recalcula as medidas do texto e repara as ligações) e envia de volta o que mudou.
- **Layout automático** também no PHP: a IA manda só nós e ligações e o servidor calcula as posições
  (em camadas, estilo dagre, para fluxos; árvore para mapas mentais e organogramas; grade para post-its).
  A IA também pode mandar coordenadas direto.

### Permissões

Hoje só o dono e o admin editam um artifact (`ArtifactPolicy::update`). No whiteboard, **o dono convida
editores**:

```
artifact_members   artifact_id, user_id, role (editor), invited_by, timestamps
                   único (artifact_id, user_id)
```

- **Editor** cria, muda e apaga elementos, envia imagens e salva versões. A IA, conectada pelo OAuth de
  um editor, também pode desenhar.
- **Só o dono e o admin** renomeiam, apagam o quadro, mudam a visibilidade e gerenciam os convidados.
- Quem não é editor e pode ver o quadro (pela visibilidade: usuários logados ou link público
  temporário) vê o quadro ao vivo, em modo só leitura (`viewModeEnabled` do Excalidraw).
- O convite é feito na tela Compartilhar, buscando pelo username ou e-mail de quem **já tem conta** no
  Ward Artifacts. Convidar alguém sem conta continua pelo `invite_user` do admin.
- O convidado vê o quadro na aba Whiteboards, na seção "Compartilhados comigo".
- Nova regra na policy: `edit(User, Artifact)` = dono, admin ou membro editor. As rotas de elementos,
  presença e as ferramentas MCP de escrita usam essa regra; `update`/`delete` ficam como estão.
- Remover um convidado tira a edição na hora (a próxima gravação dele recebe 403 e a tela passa para
  só leitura).

### Segurança

- Os elementos são JSON validado no servidor (tipos e campos conhecidos, tamanhos máximos); nada de
  HTML. O `link` de um elemento só aceita `http(s):`.
- `embeddable` só aceita o host de conteúdo do Ward (artboards) e uma lista curta de domínios.
- Limite de envios e presença por minuto por pessoa.

## Ferramentas MCP

| Ferramenta | O que faz |
|---|---|
| `create_whiteboard(title, project, template?)` | Cria o quadro (privado), devolve `url` e `preview_url` |
| `get_whiteboard(board, frame?, region?)` | Lista compacta dos elementos (id, tipo, texto, cor, posição, frame, ligações de/para), frames, quem está no quadro e o que cada um seleciona. Quadro grande: resumo por frame, e `frame`/`region` para detalhar |
| `add_elements(board, elements[], place?)` | Cria elementos no formato simples (`sticky`, `rectangle`, `ellipse`, `diamond`, `text`, `arrow` com `from`/`to`, `line`, `frame` com filhos, `image` por asset). Posição explícita ou `place` (à direita de, abaixo de, dentro do frame, grade) |
| `update_elements(board, changes[])` | Muda texto, cor, posição, tamanho, pontas da seta, frame |
| `delete_elements(board, ids[])` | Apaga (setas ligadas também) |
| `create_diagram(board, kind, nodes[], edges[], direction?, frame?)` | Fluxograma, árvore/mapa mental ou organograma a partir de nós e ligações, com layout no servidor; tudo editável depois |
| `arrange_elements(board, ids[], mode)` | `grid`, `cluster` (a IA diz os grupos e o servidor monta um frame por grupo), alinhar, distribuir |
| `show_in_canvas` (existente) | Ganha `element` e `frame` como alvo para câmera e cursor da IA |
| `save_design_version` (existente) | Também para quadros (ou `save_whiteboard_version`) |

- Skill nova `ward-whiteboard` (fluxo: abrir o quadro no navegador → `get_whiteboard` → desenhar →
  conferir → `show_in_canvas`), instruções do servidor MCP atualizadas e cópia no plugin do Ward Code.

## Riscos

- **Peso do pacote**: o Excalidraw é grande (o pacote no npm tem ~47 MB descompactado, com fontes e
  traduções; o que vai para o navegador é bem menos, em pedaços carregados sob demanda). Fica isolado na
  página do quadro; medir o tamanho real no build.
- **React no projeto**: entra só nessa entrada do Vite; o resto continua Blade/Livewire.
- **MCP App**: o app do chat hoje embute os scripts crus. Para o quadro, ou o app carrega o pacote
  compilado do nosso domínio (precisa liberar no CSP do app e o host aceitar), ou mostra uma prévia em
  SVG com link para abrir. Fica para depois do MVP.
- **Conversor PHP x Excalidraw**: o formato dos elementos pode mudar entre versões. Fixar a versão do
  pacote, ter testes com elementos gerados pelo PHP abertos no Excalidraw e passar tudo por
  `restoreElements` ao carregar.
- **Hospedagem compartilhada** com várias pessoas consultando: a consulta só lê `whiteboard_seq`
  (cacheado, como o `design_revision`), então o 204 é barato. Medir com 3–5 pessoas.
- **Texto editado por duas pessoas ao mesmo tempo no mesmo elemento**: vence a última versão (o mesmo
  que o excalidraw.com faz). Aceitável.

## Fases (sugestão; decidir mais perto)

| # | Fase | Entrega |
|---|---|---|
| WB1 | Quadro básico | `type=whiteboard`, aba Whiteboards, página com Excalidraw (pt-BR, estilo limpo), salvar elementos, ao vivo por consulta curta com `reconcileElements`, ferramenta Post-it, exportar |
| WB2 | IA | Conversor PHP, `create_whiteboard`, `get_whiteboard`, `add/update/delete_elements`, `create_diagram` com layout, `show_in_canvas` no quadro, skill `ward-whiteboard` |
| WB3 | Colaboração | Convidados editores (`artifact_members`, Compartilhar, "Compartilhados comigo"), cursores e quem está online, seguir alguém, comentários em elementos |
| WB4 | Conteúdo | Imagens como assets, versões, biblioteca Ward de formas, templates, colar Mermaid, artboard do design no quadro |
| WB5 | Extras | MCP App, `arrange_elements`, votação, cronômetro, apresentação por frames |

Cada fase: testes (Pest para gravação/conflito de versões, permissões, conversor e MCP), conferência no
navegador do painel em `localhost:8000`, commit + push (deploy automático) e checagem curta em produção.

## Decisões em aberto

1. **Ordem das fases**: confirmar a sugestão acima antes de começar.
