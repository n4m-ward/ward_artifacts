---
name: ward-artifact-edit
description: Change an artifact that is already published on Ward Artifacts (artifacts.wardtecnology.com) — fix, restyle, add features or new data — keeping the same URL. Use when the user shares an artifacts.wardtecnology.com link or a 32-character artifact id, or asks to update, adjust, "atualiza", "corrige" or "muda" an artifact published earlier. Covers reading the current source with get_artifact_source, editing locally, and publishing a new version with update_artifact; also renaming with rename_artifact.
---

# Editar um artifact publicado

O conteúdo de um artifact **só muda por uma nova versão** enviada pelo MCP: o site não edita código.
Uma nova versão mantém a URL e a visibilidade escolhida pelo usuário.

## 1. Pegue o código atual

Chame `get_artifact_source` com a URL ou o id (32 caracteres hex) do artifact.

- **HTML pequeno:** o código vem na resposta. Salve num arquivo local (ex.: `artifacts/<slug>.html`).
- **HTML grande ou bundle:** a resposta traz um `download_url` assinado (15 min):

  ```bash
  curl -sS -o artifact.zip "<download_url>"   # ou artifact.html
  unzip -q artifact.zip -d artifact/
  ```

Se o artifact foi criado nesta conversa e você ainda tem o arquivo local atualizado, pode editar
direto nele.

## 2. Edite localmente

- Mantenha as regras da skill `ward-artifact-create`: arquivo autocontido, caminhos relativos só em
  bundle, `try/catch` no storage, nada sensível no HTML.
- Faça a mudança pedida sem reescrever o que não precisa mudar.
- Pré-visualize se a mudança for visual.

## 3. Publique a nova versão

Chame `update_artifact` com o `artifact` (URL ou id):

- HTML até 2 MB: no campo `html`.
- Maior ou bundle: sem `html`, envie para o `upload_url` com curl (igual à skill `ward-artifact-publish`).

Responda com a mesma URL e o número da nova versão.

## Permissões

- Só o **dono** do artifact (ou um admin) pode enviar versões. Se a ferramenta recusar, explique e
  ofereça publicar uma cópia como artifact novo **do usuário** (`publish_artifact`), se ele quiser.
- `rename_artifact` troca só o título; a URL antiga continua redirecionando. Use apenas se o usuário
  pedir para renomear.
