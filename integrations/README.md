# Integrações com IAs

As skills do Ward Artifacts **não são públicas**: elas ficam neste repositório e são instaladas a partir
do clone local. O servidor MCP é remoto (`https://artifacts.wardtecnology.com/mcp`) e cada pessoa
autoriza o acesso com a própria conta (OAuth).

## O que tem aqui

| Caminho | O que é |
|---|---|
| `claude-code/ward-artifacts/skills/` | As 3 skills (fonte única): `ward-artifact-create`, `ward-artifact-publish`, `ward-artifact-edit` |
| `claude-code/ward-artifacts/` | Plugin do Claude Code (skills + MCP) |
| `../.claude-plugin/marketplace.json` | Marketplace local `ward` que aponta para o plugin |
| `install.sh` | Instalador para Claude Code, Codex e OpenCode |
| `AGENTS.snippet.md` | Instruções curtas para colar num `AGENTS.md` / `CLAUDE.md` |

## Instalar

```bash
git clone <repo> ward_artifacts
cd ward_artifacts
./integrations/install.sh claude            # ou: codex, opencode, all
```

O instalador:

- copia as skills para `~/.claude/skills/` (Claude Code) ou `~/.agents/skills/` (Codex e OpenCode);
- registra o MCP `ward-artifacts`: `claude mcp add --scope user`, `codex mcp add` (abre o login) ou
  o `opencode.json` global;
- com `--with-agents-md`, adiciona as instruções do Ward ao `CLAUDE.md`/`AGENTS.md` global (num bloco
  com marcadores, sem duplicar).

Depois, autentique:

| Cliente | Como |
|---|---|
| Claude Code | abra uma sessão, rode `/mcp` e autentique o `ward-artifacts` |
| Codex | o `codex mcp add` já abre o login; para refazer: `codex mcp login ward-artifacts` |
| OpenCode | `opencode mcp auth ward-artifacts` |

Opções úteis:

```bash
./integrations/install.sh all --url http://localhost:8000/mcp   # apontar para o ambiente local
./integrations/install.sh codex --skip-mcp                      # só atualizar as skills
./integrations/install.sh all --uninstall                       # remover as skills
```

Rode de novo depois de um `git pull` para atualizar as skills.

### Claude Code como plugin (alternativa)

```bash
claude plugin marketplace add /caminho/para/ward_artifacts
claude plugin install ward-artifacts@ward
```

O plugin traz as skills e o MCP apontando para produção. Use o instalador se precisar de outra URL.

## Validar

```bash
claude plugin validate integrations/claude-code/ward-artifacts
php artisan test --filter=SkillsTest
```
