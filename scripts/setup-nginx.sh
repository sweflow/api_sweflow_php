#!/usr/bin/env bash
# ============================================================
# Vupi.us API — Setup Nginx + Hardening TLS/TCP
#
# Uso:
#   sudo bash scripts/setup-nginx.sh [dominio]
#   Exemplo: sudo bash scripts/setup-nginx.sh api.typper.shop
# ============================================================

set -euo pipefail

DOMAIN="${1:-api.typper.shop}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF_SRC="$SCRIPT_DIR/nginx/${DOMAIN}.conf"
CONF_DEST="/etc/nginx/sites-available/${DOMAIN}"
CONF_LINK="/etc/nginx/sites-enabled/${DOMAIN}"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; RESET='\033[0m'
info()    { echo -e "${CYAN}[INFO]${RESET}  $*"; }
success() { echo -e "${GREEN}[OK]${RESET}    $*"; }
warn()    { echo -e "${YELLOW}[AVISO]${RESET} $*"; }
error()   { echo -e "${RED}[ERRO]${RESET}  $*" >&2; exit 1; }

[[ $EUID -ne 0 ]] && error "Execute como root: sudo bash scripts/setup-nginx.sh"

echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║   Vupi.us API — Nginx + TLS Hardening                    ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""

# ── 1. Instala Nginx se necessário ───────────────────────
if ! command -v nginx &>/dev/null; then
    info "Instalando Nginx..."
    apt-get update -qq && apt-get install -y -qq nginx
    success "Nginx instalado"
fi

# ── 2. Copia configuração do site ────────────────────────
if [[ ! -f "$CONF_SRC" ]]; then
    error "Arquivo de configuração não encontrado: $CONF_SRC"
fi

cp "$CONF_SRC" "$CONF_DEST"
success "Configuração copiada para $CONF_DEST"

# Ativa o site
if [[ ! -L "$CONF_LINK" ]]; then
    ln -s "$CONF_DEST" "$CONF_LINK"
    success "Site habilitado: $CONF_LINK"
fi

# Remove default se existir
[[ -L "/etc/nginx/sites-enabled/default" ]] && rm /etc/nginx/sites-enabled/default && warn "Site 'default' removido"

# ── 3. Hardening TLS global (/etc/nginx/nginx.conf) ──────
info "Aplicando hardening TLS global..."

NGINX_CONF="/etc/nginx/nginx.conf"
# Garante que ssl_protocols e ssl_ciphers não estejam no bloco http global
# (a config do site já define — evita conflito)
if grep -q "ssl_protocols" "$NGINX_CONF"; then
    sed -i 's/ssl_protocols.*/# ssl_protocols gerenciado por site-config/' "$NGINX_CONF"
    warn "ssl_protocols removido do nginx.conf global (gerenciado por site)"
fi

# server_tokens off no bloco http
if ! grep -q "server_tokens off" "$NGINX_CONF"; then
    sed -i '/http {/a\\tserver_tokens off;' "$NGINX_CONF"
    success "server_tokens off adicionado"
fi

# ── 4. Hardening TCP — desativa TCP timestamps ───────────
info "Desativando TCP timestamps (CVE information disclosure)..."

SYSCTL_CONF="/etc/sysctl.d/99-vupi.us-hardening.conf"
cat > "$SYSCTL_CONF" << 'EOF'
# Vupi.us API — Kernel hardening
# Desativa TCP timestamps para evitar uptime disclosure (OpenVAS LOW)
net.ipv4.tcp_timestamps = 0

# Proteção contra SYN flood
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_max_syn_backlog = 2048
net.ipv4.tcp_synack_retries = 2
net.ipv4.tcp_syn_retries = 5

# Ignora ICMP redirects (previne MITM)
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv6.conf.all.accept_redirects = 0

# Ignora source routing
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.default.accept_source_route = 0

# Log de pacotes suspeitos
net.ipv4.conf.all.log_martians = 1
EOF

sysctl -p "$SYSCTL_CONF" > /dev/null 2>&1 || true
success "Hardening TCP aplicado ($SYSCTL_CONF)"

# ── 5. Gera DH params se não existir ─────────────────────
DH_PARAMS="/etc/letsencrypt/ssl-dhparams.pem"
if [[ ! -f "$DH_PARAMS" ]]; then
    info "Gerando DH params 2048-bit (pode demorar ~1 min)..."
    mkdir -p /etc/letsencrypt
    openssl dhparam -out "$DH_PARAMS" 2048
    success "DH params gerado: $DH_PARAMS"
fi

# ── 6. Testa e recarrega Nginx ────────────────────────────
info "Testando configuração do Nginx..."
nginx -t || error "Configuração do Nginx inválida — verifique os erros acima"

systemctl reload nginx
success "Nginx recarregado"

echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║   Hardening concluído                                    ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""
echo "  ✓ TLS 1.0/1.1 desabilitados (apenas TLS 1.2 + 1.3)"
echo "  ✓ Ciphers modernos (ECDHE + CHACHA20)"
echo "  ✓ HSTS com preload (max-age=31536000)"
echo "  ✓ CSP: default-src 'none'; frame-ancestors 'none'"
echo "  ✓ OCSP Stapling ativo"
echo "  ✓ TCP timestamps desativados"
echo "  ✓ Proteção SYN flood ativa"
echo "  ✓ ICMP redirects bloqueados"
echo "  ✓ server_tokens off (versão Nginx oculta)"
echo ""
echo "  Valide em: https://www.ssllabs.com/ssltest/analyze.html?d=${DOMAIN}"
echo ""
