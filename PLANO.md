# Ward Artifacts — Plano

Plataforma para publicar artifacts HTML gerados por IA, parecida com os artifacts do Claude.
Domínio de produção: `artifacts.wardtecnology.com` (Hostinger, MySQL). Local: SQLite.

## Decisões fechadas

- **Cadastro só por convite.** Não existe rota `/register`. O primeiro admin nasce pelo
  `AdminUserSeeder` (variáveis `WARD_ADMIN_*` no `.env`). Depois disso, só um admin convida
  usuários (pela web ou pelo MCP). O convidado recebe um link para definir a senha.
- **Roles:** `admin` e `player`.
- **O conteúdo só entra via MCP.** A web não edita o código: só título, visibilidade e exclusão.
  Republicar pelo MCP cria uma nova versão e mantém a mesma URL.
- **Frontend do painel:** Livewire (starter kit oficial, Volt + Flux).
- **MCP remoto via HTTP (streamable), com OAuth.** Sem stdio.
- **Agentes suportados:** Claude Code, claude.ai, Codex, OpenCode e afins.
- **Nível de complexidade alvo:** de páginas simples até apps/jogos em canvas com sprites, JSON,
  fontes externas, `localStorage`, animação (ex.: o artifact "Vila de Teste").

## Formato do artifact

- **Padrão: um único `.html`**, com CSS, JS e imagens inline (`data:` base64, JSON como `const`).
  Recursos externos (CDN, Google Fonts) só quando inline não for possível.
- **Bundle (aprovado):** `.zip` com `index.html` + assets relativos (`assets/*.png`, `*.json`).
  Cobre artifacts como o de referência, que fazem `fetch("assets/x.json")`, sem precisar
  reescrevê-los. Mesmo limite: 100 MB descompactado (contado byte a byte na extração), até
  2000 arquivos, whitelist de extensões, sem `../` e sem symlinks. Se o zip tiver uma única
  pasta de topo (`site/index.html`), ela é removida dos caminhos.
- Cada versão fica num diretório próprio (`{uuid}/{ulid}/`); são guardadas as 10 mais recentes.
- Limite total: 100 MB.

## Visibilidade

| Nível | Quem vê | Como definir |
|---|---|---|
| `owner` (padrão) | Só o criador (e admins) | Padrão de todo artifact publicado |
| `users` | Qualquer usuário logado com o link | Tela do artifact |
| `public` | Qualquer pessoa, **até `public_until`** | Tela do artifact: 1h, 2h, 6h, 12h, 24h, 2 dias ou 7 dias |

- Quando o público expira, o artifact volta para o nível anterior (`users` ou `owner`).
  A checagem é feita a cada requisição (`public_until > now()`), sem depender de cron.
- O MCP **não aceita** campos de visibilidade.

## Painel e permissões

- **Meus artifacts:** os que eu criei.
- **Acessados:** de outros usuários, que eu já abri pelo menos uma vez (`artifact_views`).
- **Todos (admin):** com filtro por usuário. O admin vê, renomeia e apaga artifacts de qualquer um.
- O player renomeia e apaga só os próprios.
- **Usuários (admin):** convidar, reenviar convite, mudar role, remover.
- **Conexões MCP:** cada usuário vê os clientes OAuth que autorizou (nome do cliente, data,
  último uso) e pode revogar. O admin vê as conexões de todos.

## URL

```
/{username}/{project-slug}/{title-slug}-{uuid32}.html
```

- `project-slug` é escolhido pela IA a partir da feature discutida na conversa (não do repositório).
- A busca é feita só pelo `uuid`. Se o slug mudar (título renomeado), a URL antiga redireciona com 301.

## Serviço do HTML (segurança)

- A página `.html` no app é uma moldura (barra com título e controles) + `<iframe>` apontando
  para o **subdomínio de conteúdo** (`WARD_CONTENT_URL`, ex.: `content.wardtecnology.com`).
- **Por que o subdomínio é necessário:** um sandbox sem `allow-same-origin` deixa o HTML numa
  origem opaca, e aí `localStorage`, `sessionStorage`, IndexedDB e cookies **lançam erro**.
  Artifacts como o de referência quebrariam. Num subdomínio separado podemos liberar
  `allow-same-origin`: o artifact ganha storage normal e mesmo assim não alcança a sessão do app,
  porque é outra origem. Os cookies do app usam o prefixo `__Host-` (host-only) para o
  subdomínio não conseguir sobrescrevê-los.
- O HTML bruto vai com `X-Content-Type-Options: nosniff` e `X-Robots-Tag: noindex`.
- O conteúdo não-público é liberado por um token assinado de curta duração no caminho
  (`/c/{token}/index.html`). Os assets relativos de um bundle herdam o mesmo token.
- Os arquivos ficam em `storage/app/private/artifacts/{uuid}/{ulid}/`, nunca em `public/`.
- O token do conteúdo (`v{versão}.{expira}.{hmac}`) vale 3 horas e é gerado a cada abertura.
  O conteúdo vai com `Referrer-Policy: no-referrer` (o token não vaza para CDNs) e
  `frame-ancestors` restrito ao app.
- No host de conteúdo só existe `/c/...`: login, painel, uploads e MCP respondem 404 lá.
- Limitação conhecida: todos os artifacts compartilham a origem de conteúdo, então o
  `localStorage` é comum entre eles. Se isso incomodar, dá para evoluir para um subdomínio
  por artifact (`{uuid}.content...`), o que exige DNS e SSL wildcard.
- Produção: usar `SESSION_COOKIE=__Host-ward-session` e `SESSION_SECURE_COOKIE=true`, para o
  subdomínio de conteúdo não conseguir sobrescrever o cookie de sessão do app.

### Desenvolvimento local

`APP_URL=http://localhost:8000` e `WARD_CONTENT_URL=http://content.localhost:8000`. Os navegadores
resolvem `*.localhost` para 127.0.0.1, então o mesmo `php artisan serve` atende os dois hosts.

## Acesso por IAs

- **Via MCP:** a ferramenta `get_artifact_source` devolve o HTML (ou uma URL assinada para baixar).
- **Navegando pela URL (WebFetch etc.):** a moldura expõe `<link rel="alternate">`, uma
  `meta description` com o endereço do HTML e o botão "HTML puro". Assim um agente que não
  executa iframes encontra o conteúdo.
- **Download:** `/artifacts/{uuid}/download` devolve o `.html` ou um `.zip` (index + assets), que
  abrem direto no navegador. Segue a mesma permissão de visualização.
- Tudo com `noindex`: os artifacts não entram em buscadores.

## Template base

Um esqueleto mínimo, fornecido pela skill e pelo MCP (`get_template`):
- `<!doctype html>`, metas de charset e viewport, `<title>`.
- Tailwind via `@tailwindcss/browser@4.3.3` (CDN fixado) + bloco `<style type="text/tailwindcss">`
  com `@theme` (tokens de cor e fonte da Ward, claro e escuro).
- Variantes: `tailwind` (padrão), `plain` (CSS puro com as mesmas variáveis) e `canvas`
  (palco em tela cheia com loop `requestAnimationFrame`, para jogos e simulações).
- Abre direto no navegador para pré-visualizar antes de publicar (opcional).

## MCP (HTTP + OAuth, dentro do próprio Laravel)

- Pacotes: `laravel/mcp` (servidor MCP em `/mcp`, transporte streamable HTTP) +
  `laravel/passport` (OAuth 2.1 com registro dinâmico de cliente, que é o fluxo usado por
  Claude, Codex e OpenCode). O usuário conecta, faz login no site e autoriza o cliente.
- **Envio de arquivos sem stdio:** um MCP remoto não lê o disco da máquina. Por isso:
  - `publish_artifact(title, project, html?)` aceita o HTML inline (bom para claude.ai e arquivos
    pequenos) **ou** devolve uma `upload_url` assinada (15 min, uso único).
  - Agentes com terminal (Claude Code, Codex, OpenCode) enviam com
    `curl --data-binary @arquivo.html "<upload_url>"` (ou `-F file=@site.zip`). As skills ensinam
    esse passo.
  - ✅ Já implementado na Fase 2: `PUT|POST /uploads/{token}` (token de 48 caracteres, só o hash
    fica no banco, 15 min, uso único). Se o envio for recusado (422), o link continua valendo
    para uma nova tentativa.

| Ferramenta | O que faz |
|---|---|
| `get_template(variant)` | Retorna o esqueleto base |
| `publish_artifact(title, project, html?)` | Cria o artifact (sempre `owner`) e retorna a URL ou a `upload_url` |
| `update_artifact(id, html?)` | Nova versão, inline ou por `upload_url` |
| `get_artifact_source(id)` | HTML inline (até 256 KB) ou link assinado de download (15 min) |
| `list_artifacts`, `list_projects`, `whoami` | Consulta |
| `rename_artifact(id, title)` | Só o título |
| `invite_user(name, username, email, role)` | Só para admin: a ferramenta nem aparece para players |

Implementação (Fase 5):
- Servidor em `app/Mcp/Servers/WardServer.php`, rota em `routes/ai.php` (`/mcp`, `auth:api`,
  120 req/min). O `laravel/mcp` só suporta o escopo `mcp:use`, então a permissão de
  `invite_user` vem da role do usuário, não de um escopo OAuth.
- Tokens OAuth: acesso de 24h, refresh de 60 dias. O registro dinâmico (`/oauth/register`) é
  público e fica limitado a 20 req/min.
- Tokens pessoais (Bearer, 90 dias) para clientes sem OAuth, criados na tela de Conexões.
- Validado de ponta a ponta: registro dinâmico → PKCE → tela "Conectar" → token → `initialize`
  → `tools/list` → `publish_artifact`.
- Deploy: rodar `php artisan passport:keys` (as chaves ficam em `storage/`, fora do git).

## Skills (formato aberto `SKILL.md`, com descrições para auto-invocação)

- `ward-artifact-create`: como montar o HTML a partir do template (inline, base64, responsivo,
  dark mode, storage com try/catch, quando usar bundle, pré-visualização local).
- `ward-artifact-publish`: título, projeto pela feature, envio inline ou por `upload_url` com curl,
  e lembrar que o artifact nasce privado.
- `ward-artifact-edit`: `get_artifact_source` → editar localmente → `update_artifact` na mesma URL.

**Distribuição (decidida): privada, a partir do clone do repositório.** Nada de download público.
- Fonte única: `integrations/claude-code/ward-artifacts/skills/`.
- `./integrations/install.sh claude|codex|opencode|all` copia as skills (`~/.claude/skills` ou
  `~/.agents/skills`, que Codex e OpenCode leem) e registra o MCP em cada cliente. Com
  `--with-agents-md`, também adiciona as instruções ao `CLAUDE.md`/`AGENTS.md` global.
- Alternativa no Claude Code: marketplace local (`.claude-plugin/marketplace.json`) com o plugin
  `ward-artifacts@ward`.
- Detalhes em `integrations/README.md`.

## Modelo de dados

```
users              id, name, username (unique), email, password, role, created_by, timestamps
projects           id, user_id, name, slug                      unique(user_id, slug)
artifacts          id, uuid (char 32), user_id, project_id, title, slug,
                   visibility (owner|users), public_until (nullable),
                   current_version_id, size_bytes, timestamps
artifact_versions  id, artifact_id, number, kind (html|bundle), storage_path, entry_path,
                   size_bytes, sha256, created_at
artifact_views     user_id, artifact_id, first_viewed_at, last_viewed_at   unique(user_id, artifact_id)
artifact_uploads   id, token, user_id, artifact_id (nullable), expires_at, used_at
oauth_* (Passport) + last_used_at nos access tokens, para a tela de Conexões
```

## Fases

| # | Fase | Status |
|---|---|---|
| 0 | Setup: Laravel 12, starter kit Livewire, SQLite, git, seeder do admin, cadastro desligado | ✅ |
| 1 | Usuários: convite por e-mail (link para definir senha), tela de admin, roles, policies | ✅ |
| 2 | Artifacts: modelos, storage, versões, projects, validação do HTML e do zip, links de upload | ✅ |
| 3 | Viewer seguro: moldura + iframe no subdomínio de conteúdo, token assinado, views, redirect 301, download | ✅ |
| 4 | Painel: Meus / Acessados / Todos, renomear, apagar, visibilidade + público temporário | ✅ |
| 5 | MCP HTTP + OAuth (Passport), `upload_url`, tela de Conexões MCP, template base | ✅ |
| 6 | Skills, plugin do Claude Code, instalador local, `AGENTS.md`, guia de conexão para Codex/OpenCode | ✅ |
| 7 | Conta e senha: trocar senha (tela própria, em português), "esqueci minha senha" por e-mail, admin envia link de redefinição para um usuário, app inteiro em pt-BR | ✅ |
| 8 | Hardening e deploy na Hostinger (MySQL, SMTP, subdomínio de conteúdo + SSL, limites de upload) — ver DEPLOY.md | 🚧 |
