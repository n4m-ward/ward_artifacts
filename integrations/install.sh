#!/usr/bin/env bash
#
# Instala as skills do Ward Artifacts e registra o servidor MCP nos clientes de
# IA desta máquina. Roda a partir do clone do repositório: as skills não são
# distribuídas publicamente.
#
#   ./integrations/install.sh claude              # Claude Code
#   ./integrations/install.sh codex opencode      # Codex e OpenCode
#   ./integrations/install.sh all --url http://localhost:8000/mcp
#
# Opções:
#   --url <url>        Servidor MCP (padrão: https://artifacts.wardtecnology.com/mcp ou $WARD_MCP_URL)
#   --skip-mcp         Só copia as skills, não mexe na configuração de MCP
#   --with-agents-md   Também adiciona as instruções do Ward ao AGENTS.md global do cliente
#   --uninstall        Remove as skills (e o bloco do AGENTS.md); o MCP fica para você remover
#
# Rode de novo depois de um "git pull" para atualizar as skills.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SKILLS_SRC="$REPO_DIR/integrations/claude-code/ward-artifacts/skills"
SNIPPET="$REPO_DIR/integrations/AGENTS.snippet.md"
SERVER_NAME="ward-artifacts"
MCP_URL="${WARD_MCP_URL:-https://artifacts.wardtecnology.com/mcp}"
SKIP_MCP=0
WITH_AGENTS_MD=0
UNINSTALL=0
CLIENTS=()

usage() {
    sed -n '3,19p' "$0" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

while [ $# -gt 0 ]; do
    case "$1" in
        claude|codex|opencode) CLIENTS+=("$1") ;;
        all) CLIENTS+=(claude codex opencode) ;;
        --url) MCP_URL="${2:?--url precisa de um valor}"; shift ;;
        --url=*) MCP_URL="${1#--url=}" ;;
        --skip-mcp) SKIP_MCP=1 ;;
        --with-agents-md) WITH_AGENTS_MD=1 ;;
        --uninstall) UNINSTALL=1 ;;
        -h|--help) usage 0 ;;
        *) echo "Opção desconhecida: $1" >&2; usage 1 ;;
    esac
    shift
done

[ ${#CLIENTS[@]} -gt 0 ] || usage 1
[ -d "$SKILLS_SRC" ] || { echo "Skills não encontradas em $SKILLS_SRC" >&2; exit 1; }

say() { printf '\033[1m%s\033[0m\n' "$*"; }
info() { printf '  %s\n' "$*"; }
has() { command -v "$1" >/dev/null 2>&1; }

# Copia (ou remove) as skills num diretório de skills do cliente.
sync_skills() {
    local dest="$1"

    for skill in "$SKILLS_SRC"/*/; do
        local name
        name="$(basename "$skill")"
        rm -rf "${dest:?}/$name"

        if [ "$UNINSTALL" -eq 0 ]; then
            mkdir -p "$dest"
            cp -R "$skill" "$dest/$name"
        fi
    done

    if [ "$UNINSTALL" -eq 0 ]; then
        info "skills copiadas para $dest"
    else
        info "skills removidas de $dest"
    fi
}

# Adiciona/atualiza (ou remove) o bloco do Ward num AGENTS.md, entre marcadores.
sync_agents_md() {
    local file="$1"
    [ "$WITH_AGENTS_MD" -eq 1 ] || [ "$UNINSTALL" -eq 1 ] || return 0
    [ -f "$file" ] || [ "$UNINSTALL" -eq 0 ] || return 0

    mkdir -p "$(dirname "$file")"
    touch "$file"

    python3 - "$file" "$SNIPPET" "$UNINSTALL" <<'PY'
import os, re, sys
path, snippet_path, uninstall = sys.argv[1], sys.argv[2], sys.argv[3] == "1"
start, end = "<!-- ward-artifacts:start -->", "<!-- ward-artifacts:end -->"
text = open(path, encoding="utf-8").read()
text = re.sub(r"\n*" + re.escape(start) + r".*?" + re.escape(end) + r"\n?", "\n", text, flags=re.S).rstrip("\n")
if not uninstall:
    block = f"{start}\n{open(snippet_path, encoding='utf-8').read().strip()}\n{end}"
    text = (text + "\n\n" + block) if text else block
if text:
    open(path, "w", encoding="utf-8").write(text + "\n")
else:
    os.remove(path)
PY
    info "instruções do Ward atualizadas em $file"
}

install_claude() {
    say "Claude Code"
    sync_skills "$HOME/.claude/skills"
    sync_agents_md "$HOME/.claude/CLAUDE.md"

    [ "$SKIP_MCP" -eq 0 ] && [ "$UNINSTALL" -eq 0 ] || return 0

    if ! has claude; then
        info "CLI 'claude' não encontrada. Depois de instalar, rode:"
        info "  claude mcp add --transport http --scope user $SERVER_NAME $MCP_URL"
        return 0
    fi

    if claude mcp get "$SERVER_NAME" >/dev/null 2>&1; then
        info "MCP '$SERVER_NAME' já configurado (para trocar a URL: claude mcp remove $SERVER_NAME -s user)"
    else
        claude mcp add --transport http --scope user "$SERVER_NAME" "$MCP_URL"
        info "MCP adicionado. Abra o Claude Code, rode /mcp e autentique o '$SERVER_NAME'."
    fi
}

install_codex() {
    say "Codex"
    sync_skills "$HOME/.agents/skills"
    sync_agents_md "${CODEX_HOME:-$HOME/.codex}/AGENTS.md"

    [ "$SKIP_MCP" -eq 0 ] && [ "$UNINSTALL" -eq 0 ] || return 0

    if ! has codex; then
        info "CLI 'codex' não encontrada. Depois de instalar, rode:"
        info "  codex mcp add $SERVER_NAME --url $MCP_URL"
        return 0
    fi

    if codex mcp get "$SERVER_NAME" >/dev/null 2>&1; then
        info "MCP '$SERVER_NAME' já configurado. Para autenticar de novo: codex mcp login $SERVER_NAME"
    else
        # O Codex detecta o OAuth e já abre o login no navegador.
        codex mcp add "$SERVER_NAME" --url "$MCP_URL"
    fi
}

install_opencode() {
    local config="${XDG_CONFIG_HOME:-$HOME/.config}/opencode/opencode.json"

    say "OpenCode"
    sync_skills "$HOME/.agents/skills"
    sync_agents_md "${XDG_CONFIG_HOME:-$HOME/.config}/opencode/AGENTS.md"

    [ "$SKIP_MCP" -eq 0 ] && [ "$UNINSTALL" -eq 0 ] || return 0

    if ! has python3; then
        info "Adicione ao $config:"
        info "  { \"mcp\": { \"$SERVER_NAME\": { \"type\": \"remote\", \"url\": \"$MCP_URL\" } } }"
        return 0
    fi

    mkdir -p "$(dirname "$config")"
    python3 - "$config" "$SERVER_NAME" "$MCP_URL" <<'PY'
import json, os, sys
path, name, url = sys.argv[1:4]
data = {}
if os.path.exists(path) and os.path.getsize(path) > 0:
    with open(path, encoding="utf-8") as f:
        data = json.load(f)
data.setdefault("$schema", "https://opencode.ai/config.json")
data.setdefault("mcp", {})[name] = {"type": "remote", "url": url, "enabled": True}
with open(path, "w", encoding="utf-8") as f:
    json.dump(data, f, indent=2, ensure_ascii=False)
    f.write("\n")
PY
    info "MCP '$SERVER_NAME' configurado em $config"
    info "Para autenticar: opencode mcp auth $SERVER_NAME"
}

for client in "${CLIENTS[@]}"; do
    "install_$client"
done

[ "$UNINSTALL" -eq 1 ] || say "Pronto. Servidor MCP: $MCP_URL"
