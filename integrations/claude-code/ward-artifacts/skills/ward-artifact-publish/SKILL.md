---
name: ward-artifact-publish
description: Publish an HTML artifact to Ward Artifacts (artifacts.wardtecnology.com) through the ward-artifacts MCP server. Use right after creating an artifact, or when the user says to publish, upload, share, "sobe", "publica" or "manda pro Ward" a page, report, prototype or game. Covers choosing the title and the project (the feature being discussed, not the repo), sending small HTML inline and large files or .zip bundles through the one-time upload link with curl, and telling the user the artifact starts private.
---

# Publicar no Ward Artifacts

## Antes de publicar

- **É uma alteração?** Se o artifact já foi publicado nesta conversa, ou o usuário passou a URL de um
  artifact existente, **não publique outro**: use a skill `ward-artifact-edit` (`update_artifact`), que
  mantém a mesma URL.
- **Título:** curto e descritivo ("Vila de Teste", "Funil de vendas — outubro").
- **Projeto (`project`):** o nome da **feature ou assunto da conversa atual**, não o nome do
  repositório ("Sistema de mapas", "Onboarding v2"). Artifacts da mesma feature devem usar o mesmo nome:
  consulte `list_projects` e reaproveite um nome existente quando fizer sentido.

## HTML até 2 MB: envio direto

Chame `publish_artifact` com `title`, `project` e o HTML completo em `html`.

## Arquivo grande ou bundle .zip: link de upload

1. Chame `publish_artifact` **sem** o campo `html`. A resposta traz um `upload_url` de uso único
   (vale 15 minutos).
2. Envie o arquivo:

   ```bash
   # HTML único
   curl -sS -X PUT --data-binary @caminho/index.html "<upload_url>"

   # Bundle: zipe o CONTEÚDO da pasta (index.html na raiz do zip)
   (cd caminho/da/pasta && zip -qr ../site.zip .)
   curl -sS -F file=@caminho/da/site.zip "<upload_url>"
   ```

3. A resposta é um JSON com `artifact.url`.
   - **422:** o arquivo foi recusado; a mensagem diz o que corrigir. Corrija e envie de novo para o
     **mesmo** link.
   - **410:** o link expirou ou já foi usado; peça um novo chamando a ferramenta de novo.

Se você não tiver terminal (ex.: claude.ai), só dá para usar o envio direto: mantenha o HTML abaixo
de 2 MB, comprimindo imagens ou usando SVG.

## Depois de publicar

- **Confira o resultado (se você tiver navegador):** a resposta traz `preview_url`, um link de uso
  único que abre o artifact **sem login**. Abra-o no seu navegador (Playwright, Chrome etc.) para ver se
  renderizou certo. Ele morre no primeiro acesso; se precisar de outro, chame `get_artifact_source`.
- Entregue ao usuário a **URL normal** (`url`), nunca o `preview_url`.
- Diga que o artifact está **privado (só ele vê)**. Para compartilhar, ele abre o link e clica no selo
  de visibilidade ("Só eu"), onde escolhe "Usuários logados" ou um link público temporário (de 1 hora a
  1 semana).
- **Nunca** diga que o link é público, nem tente torná-lo público: isso só pode ser feito pelo usuário,
  na tela do artifact.
