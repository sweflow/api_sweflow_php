#!/bin/bash
# ci/caddy-env.sh — Gera /etc/caddy/sweflow.env com as variáveis que o Caddy precisa
#
# O systemd EnvironmentFile não processa aspas, comentários inline nem
# variáveis com caracteres especiais da mesma forma que o PHP/bash.
# Este script extrai apenas as 4 variáveis necessárias do .env do projeto
# e gera um arquivo limpo e seguro para o systemd.
#
# Uso (rode sempre que mudar APP_DOMAIN, APP_PORT, APP_HOST ou CADDY_EMAIL):
#   sudo bash ci/caddy-env.sh
#   sudo systemctl restart caddy
#
# Ou para recarregar sem downtime:
#   sudo bash ci/caddy-env.sh && sudo systemctl reload caddy

set -euo pipefail

PROJETO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${PROJETO_DIR}/.env"
OUT_FILE="/etc/caddy/sweflow.env"

if [ ! -f "$ENV_FILE" ]; then
    echo "Erro: .env não encontrado em $ENV_FILE"
    exit 1
fi

# Extrai o valor de uma variável do .env (ignora comentários e aspas)
get_env() {
    local key="$1"
    local default="${2:-}"
    local val
    val=$(grep -E "^${key}=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed "s/^['\"]//;s/['\"]$//;s/#.*$//" | xargs)
    echo "${val:-$default}"
}

APP_DOMAIN=$(get_env "APP_DOMAIN" "api.vupi.us")
APP_PORT=$(get_env "APP_PORT" "8000")
APP_HOST=$(get_env "APP_HOST" "127.0.0.1")
CADDY_EMAIL=$(get_env "CADDY_EMAIL" "admin@vupi.us")

# Valida que APP_DOMAIN não tem protocolo
if [[ "$APP_DOMAIN" =~ ^https?:// ]]; then
    echo "Erro: APP_DOMAIN não deve ter protocolo. Valor atual: $APP_DOMAIN"
    echo "Corrija no .env: APP_DOMAIN=api.vupi.us (sem https://)"
    exit 1
fi

# Valida porta numérica
if ! [[ "$APP_PORT" =~ ^[0-9]+$ ]] || [ "$APP_PORT" -lt 1 ] || [ "$APP_PORT" -gt 65535 ]; then
    echo "Erro: APP_PORT inválido: $APP_PORT"
    exit 1
fi

# Gera o arquivo limpo para o systemd
cat > "$OUT_FILE" << EOF
# Gerado automaticamente por ci/caddy-env.sh em $(date -u +"%Y-%m-%dT%H:%M:%SZ")
# NÃO EDITE MANUALMENTE — edite o .env do projeto e rode: sudo bash ci/caddy-env.sh
APP_DOMAIN=${APP_DOMAIN}
APP_PORT=${APP_PORT}
APP_HOST=${APP_HOST}
CADDY_EMAIL=${CADDY_EMAIL}
EOF

chmod 640 "$OUT_FILE"
chown root:caddy "$OUT_FILE" 2>/dev/null || chown root:root "$OUT_FILE"

echo "✔ /etc/caddy/sweflow.env gerado:"
echo "  APP_DOMAIN  = $APP_DOMAIN"
echo "  APP_PORT    = $APP_PORT"
echo "  APP_HOST    = $APP_HOST"
echo "  CADDY_EMAIL = $CADDY_EMAIL"
echo ""
echo "Próximo passo:"
echo "  sudo systemctl restart caddy"
echo "  sudo systemctl show caddy | grep -A4 'Environment'"
