# Ward Artifacts — Plano

Plataforma para publicar artifacts HTML gerados por IA, parecida com os artifacts do Claude.
Domínio de produção: `artifacts.wardtecnology.com` (Hostinger, MySQL). Local: SQLite.

## Decisões fechadas

- **Cadastro só por convite.** Não existe rota `/register`. O primeiro admin nasce pelo
  `AdminUserSeeder` (variáveis `WARD_ADMIN_*` no `.env`). Depois disso, só um admin cadastra
  usuários (pela web ou pelo MCP).
- **Roles:** `admin` e `player`.
- **Artifact = sempre um único arquivo `.html`**, com CSS, JS e imagens inline (`data:` base64).
  Recursos externos (CDN, imagens remotas) só quando inline não for possível. Limite: 100 MB.
- **O conteúdo só entra via MCP/API.** A web não edita o código: só título, visibilidade e exclusão.
  Republicar pelo MCP cria uma nova versão e mantém a mesma URL.
- **Frontend do painel:** Livewire (starter kit oficial, Volt + Flux).
- **Agentes suportados:** Claude Code, Codex, OpenCode e afins (MCP + skills no formato aberto `SKILL.md`).

## Visibilidade

| Nível | Quem vê | Como definir |
|---|---|---|
| `owner` (padrão) | Só o criador (e admins) | Padrão de todo artifact publicado |
| `users` | Qualquer usuário logado com o link | Tela do artifact |
| `public` | Qualquer pessoa, **até `public_until`** | Tela do artifact: 1h, 2h, 6h, 12h, 24h, 2 dias ou 7 dias |

- Quando o público expira, o artifact volta para o nível anterior (`users` ou `owner`).
  A checagem é feita a cada requisição (`public_until > now()`), sem depender de cron.
- A API e o MCP **não aceitam** campos de visibilidade.

## Painel e permissões

- **Meus artifacts:** os que eu criei.
- **Acessados:** de outros usuários, que eu já abri pelo menos uma vez (`artifact_views`).
- **Todos (admin):** com filtro por usuário. O admin vê, renomeia e apaga artifacts de qualquer um.
- O player renomeia e apaga só os próprios.

## URL

```
/{username}/{project-slug}/{title-slug}-{uuid32}.html
```

- `project-slug` é escolhido pela IA a partir da feature discutida na conversa (não do repositório).
- A busca é feita só pelo `uuid`. Se o slug mudar (título renomeado), a URL antiga redireciona com 301.

## Serviço do HTML (segurança)

- A página `.html` é uma moldura (barra com título e controles) + `<iframe sandbox="allow-scripts ...">`
  sem `allow-same-origin`.
- O HTML bruto é servido com `Content-Security-Policy: sandbox allow-scripts allow-popups allow-forms
  allow-modals allow-downloads`, `X-Content-Type-Options: nosniff` e `X-Robots-Tag: noindex`.
  Assim ele roda numa origem opaca, mesmo se a URL bruta for aberta direto, e não acessa a sessão.
- O bruto não-público é liberado por um token assinado de curta duração gerado pela moldura.
- `WARD_CONTENT_URL` permite mover o bruto para um subdomínio depois, sem mudar código.
- Os arquivos ficam em `storage/app/private/artifacts/{uuid}/v{n}.html`, nunca em `public/`.

## Acesso por IAs

- **Com token (MCP/API):** `GET /api/artifacts/{uuid}/source` retorna o HTML puro para a IA ler
  ou editar. O token Sanctum também é aceito na rota bruta.
- **Navegando pela URL (WebFetch etc.):** a moldura expõe `<link rel="alternate">` e um link visível
  para o HTML bruto. Assim um agente que não executa iframes encontra o conteúdo.
- Tudo com `noindex`: os artifacts não entram em buscadores.

## Template base

Um esqueleto mínimo, fornecido pela skill e pelo MCP (`get_template`):
- `<!doctype html>`, metas de charset e viewport, `<title>`.
- Tailwind via `@tailwindcss/browser@4.3.3` (CDN fixado) + bloco `<style type="text/tailwindcss">`
  com `@theme` (tokens de cor e fonte da Ward, claro e escuro).
- Variantes: `tailwind` (padrão) e `plain` (CSS puro com as mesmas variáveis).
- Abre direto no navegador para pré-visualizar antes de publicar (opcional).

## Modelo de dados

```
users              id, name, username (unique), email, password, role, created_by, timestamps
projects           id, user_id, name, slug                      unique(user_id, slug)
artifacts          id, uuid (char 32), user_id, project_id, title, slug,
                   visibility (owner|users), public_until (nullable),
                   current_version_id, size_bytes, timestamps
artifact_versions  id, artifact_id, number, storage_path, size_bytes, sha256, created_at
artifact_views     user_id, artifact_id, first_viewed_at, last_viewed_at   unique(user_id, artifact_id)
personal_access_tokens (Sanctum)  abilities: artifacts:read, artifacts:write, users:create
```

## MCP (`ward-artifacts-mcp`, Node/TypeScript, stdio via `npx`)

O MCP roda localmente porque precisa ler o arquivo do disco. Um MCP remoto obrigaria a IA a colar o
HTML inteiro como argumento.

| Ferramenta | O que faz |
|---|---|
| `get_template(variant)` | Retorna o esqueleto base |
| `preview_local(path)` | Abre o arquivo no navegador |
| `publish_artifact(path, title, project)` | Envia o arquivo e retorna a URL (sempre `owner`) |
| `update_artifact(id, path)` | Cria uma nova versão |
| `get_artifact_source(id, dest)` | Baixa o HTML para editar localmente |
| `list_artifacts`, `list_projects`, `whoami` | Consulta |
| `create_user(name, username, email, role)` | Só para admin, com a ability `users:create` |

Um instalador (`npx ward-artifacts-mcp install --client claude|codex|opencode`) grava a config do
MCP (`.mcp.json`, `~/.codex/config.toml`, `opencode.json`) e copia as skills.

## Skills (formato `SKILL.md`, com descrições para auto-invocação)

- `ward-artifact-create`: como montar o HTML único a partir do template (inline, base64, responsivo, dark mode).
- `ward-artifact-publish`: quando publicar, como nomear o projeto pela feature e lembrar que o artifact nasce privado.
- `ward-artifact-edit`: baixar o código, editar e republicar no mesmo id.

Distribuição: plugin do Claude Code + pasta `.agents/skills` e `AGENTS.md` para Codex e OpenCode.

## Fases

| # | Fase | Status |
|---|---|---|
| 0 | Setup: Laravel 12, starter kit Livewire, SQLite, git, seeder do admin, cadastro desligado | ✅ |
| 1 | Usuários: tela de admin para convidar/cadastrar usuários, roles, policies | ⏳ |
| 2 | Upload: API Sanctum, validação do HTML, versões, projects, tela de tokens | ⏳ |
| 3 | Viewer seguro: moldura + iframe, rota bruta com CSP sandbox, token assinado, views, redirect 301 | ⏳ |
| 4 | Painel: Meus / Acessados / Todos, renomear, apagar, visibilidade + público temporário | ⏳ |
| 5 | MCP local + template base | ⏳ |
| 6 | Skills, plugin do Claude Code, `AGENTS.md`, instalador | ⏳ |
| 7 | Hardening e deploy na Hostinger (MySQL, limites de upload do PHP, cron, HTTPS) | ⏳ |
