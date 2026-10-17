---
name: ward-artifact-create
description: Build a standalone HTML artifact for Ward Artifacts (artifacts.wardtecnology.com). Use whenever the user wants something visual, interactive or shareable that is best delivered as a web page — report, dashboard, chart, prototype, UI mockup, landing page, game, simulation, diagram, comparison, interactive demo — or asks for an "artifact", "artefato", "página HTML" or "Ward artifact". Covers starting from the Ward template (Tailwind, plain CSS or canvas), keeping everything in one self-contained .html (inline CSS/JS, images as data URIs), when to use a .zip bundle instead, and previewing locally before publishing.
---

# Criar um artifact Ward

Um artifact Ward é **uma página HTML que abre sozinha no navegador**, sem servidor e sem build.
Depois de pronta, ela é publicada com a skill `ward-artifact-publish`.

## 1. Comece pelo template

Chame a ferramenta MCP `get_template` do servidor `ward-artifacts`:

| variant | Quando usar |
|---|---|
| `tailwind` (padrão) | Relatórios, dashboards, protótipos de UI, páginas em geral |
| `plain` | Quando não quiser depender de CDN (CSS puro com os mesmos tokens) |
| `canvas` | Jogos, simulações, visualizações animadas (palco em tela cheia + loop `requestAnimationFrame`) |

Os templates já trazem os tokens de cor da Ward (`bg-surface`, `text-ink`, `text-muted`, `border-line`,
`bg-brand`...) com tema escuro automático. Use esses tokens em vez de inventar uma paleta.

Se o MCP `ward-artifacts` não estiver conectado, peça ao usuário para conectá-lo (página
**Conexões MCP** do site) antes de continuar.

## 2. Um único arquivo, tudo inline

- **CSS e JS** dentro do próprio HTML (`<style>`, `<script>`).
- **Imagens** como data URI: `<img src="data:image/png;base64,...">`. Para converter:
  - macOS: `base64 -i imagem.png | tr -d '\n'`
  - Linux: `base64 -w0 imagem.png`
  - Prefira SVG inline para ícones e ilustrações simples.
- **Dados** (JSON, CSV) como constantes no `<script>`, não como arquivos separados.
- **Bibliotecas externas** só via CDN e com **versão fixa** (`cdn.jsdelivr.net/npm/pacote@1.2.3`,
  `cdnjs.cloudflare.com`), nunca `@latest`. Google Fonts pode ser usado.
- Nada de caminhos relativos (`./img.png`) num HTML único: eles não existem depois de publicado.

### Quando usar um bundle .zip

Use um `.zip` com `index.html` na raiz + `assets/...` quando inline não for razoável: muitos sprites,
áudio, vídeo, modelos 3D, ou mais de ~2 MB de binários. No bundle, use **caminhos relativos**
(`assets/mapa.png`, `fetch("assets/dados.json")`). Limite total: **100 MB**, até 2000 arquivos, só tipos
web comuns (html, css, js, json, imagens, fontes, áudio, vídeo, glb/gltf, wasm, pdf).

## 3. Qualidade mínima

- **Responsivo:** precisa funcionar de 360 px a telas largas, sem rolagem horizontal; margens laterais
  de pelo menos 16 px.
- **Tema escuro:** mantenha o `prefers-color-scheme` do template.
- **Acessível:** contraste legível, `<title>` com o nome real, `aria-label` em controles só com ícone,
  foco visível.
- **Storage:** `localStorage`/`sessionStorage` podem falhar; sempre use `try/catch` (o template traz o
  helper `store`).
- **Nada sensível:** nunca coloque tokens, senhas, chaves de API ou dados pessoais no HTML. O dono pode
  tornar o artifact público depois.
- Sem rastreadores, analytics ou formulários que coletem credenciais.

## 4. Pré-visualize (opcional, recomendado)

Salve o arquivo (ex.: `artifacts/<nome>.html`) e abra no navegador:

- macOS: `open artifacts/<nome>.html`
- Linux: `xdg-open artifacts/<nome>.html`

Para um **bundle**, abrir o `index.html` direto do disco bloqueia `fetch()` de arquivos locais. Sirva a
pasta: `python3 -m http.server 8123 --directory <pasta>` e abra `http://localhost:8123`.

## 5. Publique

Siga a skill `ward-artifact-publish`.
