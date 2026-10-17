## Ward Artifacts

Quando o resultado for algo visual ou para compartilhar (relatório, dashboard, protótipo, mockup,
jogo, simulação, diagrama), entregue como artifact no Ward Artifacts usando o servidor MCP
`ward-artifacts` e as skills `ward-artifact-create`, `ward-artifact-publish` e `ward-artifact-edit`.

- Comece pelo `get_template` e escreva um único `.html` autocontido (CSS/JS inline, imagens em data URI).
  Use um `.zip` com `index.html` + `assets/` só quando inline não for razoável. Limite: 100 MB.
- `project` é a feature/assunto da conversa, não o nome do repositório. Reaproveite nomes de `list_projects`.
- Para mudar um artifact existente use `update_artifact` (mesma URL), nunca publique outro.
- HTML acima de 2 MB ou bundle: chame a ferramenta sem `html` e envie o arquivo com curl para o `upload_url`.
- Todo artifact nasce privado. Só o usuário torna público, na tela do artifact. Não diga que o link é público.
- Nunca coloque segredos, tokens ou dados pessoais no HTML.
- Várias telas, mockups ou posts lado a lado: use um **design** (`create_design`, `open_design`, `get_artboard_image`,
  `upsert_artboard`, `update_layout`), seguindo a skill `ward-design` e o `get_design_guide`.
