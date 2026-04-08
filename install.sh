#!/usr/bin/env bash
# ============================================================
# Vupi.us API — Instalação e Setup em um único comando
#
# Uso:
#   curl -fsSL https://raw.githubusercontent.com/seu-repo/main/install.sh | sudo bash
#
# Ou localmente (após clonar o repositório):
#   sudo bash install.sh
# ============================================================

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

info()    { echo -e "${CYAN}[INFO]${RESET}  $*"; }
success() { echo -e "${GREEN}[OK]${RESET}    $*"; }
warn()    { echo -e "${YELLOW}[AVISO]${RESET} $*"; }
error()   { echo -e "${RED}[ERRO]${RESET}  $*" >&2; exit 1; }
step()    { echo -e "\n${BOLD}${CYAN}>> $*${RESET}"; }

[[ $EUID -ne 0 ]] && error "Execute como root: sudo bash install.sh"

OS_ID=$(. /etc/os-release && echo "$ID")
[[ "$OS_ID" != "ubuntu" ]] && error "Este script e para Ubuntu. Detectado: $OS_ID"

PHP_VERSION="8.2"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="${PROJECT_DIR:-$SCRIPT_DIR}"
APP_USER="${SUDO_USER:-ubuntu}"

echo ""
echo -e "${BOLD}╔══════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}║           Vupi.us API — Instalacao Completa          ║${RESET}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════╝${RESET}"
echo ""
info "Projeto: $PROJECT_DIR"
info "Usuario: $APP_USER"
echo ""

# ── 1. Sistema ───────────────────────────────────────────
step "1/8 - Atualizando sistema"
apt-get update -qq
apt-get install -y -qq \
    ca-certificates curl gnupg lsb-release \
    software-properties-common apt-transport-https \
    git unzip zip wget ufw
success "Sistema atualizado"

# ── 2. Docker ────────────────────────────────────────────
step "2/8 - Docker Engine"
if command -v docker &>/dev/null; then
    warn "Docker ja instalado: $(docker --version | awk '{print $3}' | tr -d ',')"
else
    apt-get remove -y -qq docker docker-engine docker.io containerd runc 2>/dev/null || true
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update -qq
    apt-get install -y -qq \
        docker-ce docker-ce-cli containerd.io \
        docker-buildx-plugin docker-compose-plugin
    systemctl enable --now docker
    success "Docker instalado"
fi

if [[ -n "$APP_USER" && "$APP_USER" != "root" ]]; then
    usermod -aG docker "$APP_USER" 2>/dev/null || true
fi

# ── 3. PHP 8.2 ──────────────────────────────────────────
step "3/8 - PHP $PHP_VERSION + extensoes"
if ! php -v 2>/dev/null | grep -q "PHP $PHP_VERSION"; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -qq
    apt-get install -y -qq \
        "php${PHP_VERSION}" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-fpm" \
        "php${PHP_VERSION}-common" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-mbstring" \
        "php${PHP_VERSION}-xml" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-bcmath" \
        "php${PHP_VERSION}-intl" "php${PHP_VERSION}-opcache" "php${PHP_VERSION}-gd" \
        "php${PHP_VERSION}-readline"
    update-alternatives --set php "/usr/bin/php${PHP_VERSION}" 2>/dev/null || true
fi

# Driver PostgreSQL
if ! php -m | grep -q pdo_pgsql; then
    apt-get install -y -qq "php${PHP_VERSION}-pgsql" libpq-dev
    phpenmod -v "$PHP_VERSION" pgsql pdo_pgsql 2>/dev/null || true
fi

# Driver MySQL
if ! php -m | grep -q pdo_mysql; then
    apt-get install -y -qq "php${PHP_VERSION}-mysql" default-libmysqlclient-dev
    phpenmod -v "$PHP_VERSION" mysql pdo_mysql mysqli 2>/dev/null || true
fi

success "PHP $(php -v | head -1 | awk '{print $2}') | pdo_pgsql: $(php -m | grep -q pdo_pgsql && echo OK || echo FALTANDO) | pdo_mysql: $(php -m | grep -q pdo_mysql && echo OK || echo FALTANDO)"

# ── 4. PHP.ini producao ──────────────────────────────────
step "4/8 - Configurando PHP para producao"
for ini in "/etc/php/${PHP_VERSION}/cli/php.ini" "/etc/php/${PHP_VERSION}/fpm/php.ini"; do
    [[ ! -f "$ini" ]] && continue
    sed -i 's/^;*\s*expose_php\s*=.*/expose_php = Off/'         "$ini"
    sed -i 's/^;*\s*display_errors\s*=.*/display_errors = Off/' "$ini"
    sed -i 's/^;*\s*log_errors\s*=.*/log_errors = On/'          "$ini"
    sed -i 's/^;*\s*memory_limit\s*=.*/memory_limit = 256M/'    "$ini"
    sed -i 's/^;*\s*max_execution_time\s*=.*/max_execution_time = 60/' "$ini"
done
systemctl restart "php${PHP_VERSION}-fpm" 2>/dev/null || true
success "PHP configurado"

# ── 5. Composer 2 ────────────────────────────────────────
step "5/8 - Composer 2"
if command -v composer &>/dev/null; then
    composer self-update --2 2>/dev/null || true
    warn "Composer ja instalado"
else
    EXPECTED="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
    [[ "$EXPECTED" != "$ACTUAL" ]] && { rm composer-setup.php; error "Checksum Composer invalido"; }
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer --2
    rm composer-setup.php
    success "Composer instalado"
fi

# ── 6. Firewall ──────────────────────────────────────────
step "6/8 - Firewall UFW"
if command -v ufw &>/dev/null; then
    ufw --force enable
    ufw allow ssh; ufw allow 80/tcp; ufw allow 443/tcp
    APP_PORT=3005
    [[ -f "$PROJECT_DIR/.env" ]] && {
        _P=$(grep -E '^APP_PORT=' "$PROJECT_DIR/.env" 2>/dev/null | cut -d= -f2 | tr -d '"' || true)
        APP_PORT="${_P:-3005}"
    }
    ufw allow "${APP_PORT}/tcp"
    success "UFW: SSH, 80, 443, ${APP_PORT}"
fi

# ── 7. Dependencias do projeto ───────────────────────────
step "7/8 - Instalando dependencias do projeto"
cd "$PROJECT_DIR"
if [[ ! -d "$PROJECT_DIR/vendor" ]]; then
    if [[ -n "$APP_USER" && "$APP_USER" != "root" ]]; then
        sudo -u "$APP_USER" composer install --no-interaction --prefer-dist --optimize-autoloader
    else
        composer install --no-interaction --prefer-dist --optimize-autoloader
    fi
    success "Dependencias instaladas"
else
    warn "vendor/ ja existe — pulando composer install"
fi

# ── 8. Vupi.us Setup ─────────────────────────────────────
step "8/8 - Executando Vupi.us Setup"
echo ""

if [[ -n "$APP_USER" && "$APP_USER" != "root" ]]; then
    sudo -u "$APP_USER" php vupi setup --auto --db-mode=compose --server=php
else
    php vupi setup --auto --db-mode=compose --server=php
fi

echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}${GREEN}║              Instalacao concluida!                   ║${RESET}"
echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════╝${RESET}"
echo ""
[[ -n "$APP_USER" && "$APP_USER" != "root" ]] && \
    warn "Faca logout e login novamente para usar Docker sem sudo"
