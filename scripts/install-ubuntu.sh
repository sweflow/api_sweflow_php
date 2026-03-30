#!/usr/bin/env bash
# ============================================================
# Sweflow API — Instalação automática no Ubuntu Server
#
# Instala:
#   - Docker Engine + Docker Compose plugin
#   - PHP 8.2 + extensões (pdo_pgsql, pdo_mysql, curl, mbstring, etc.)
#   - Composer 2
#   - Drivers PostgreSQL e MySQL
#
# Testado em: Ubuntu 22.04 LTS / 24.04 LTS
#
# Uso:
#   chmod +x scripts/install-ubuntu.sh
#   sudo ./scripts/install-ubuntu.sh
#
# Ou em uma linha:
#   curl -fsSL https://raw.githubusercontent.com/seu-repo/main/scripts/install-ubuntu.sh | sudo bash
# ============================================================

set -euo pipefail

# ── Cores ────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

info()    { echo -e "${CYAN}[INFO]${RESET}  $*"; }
success() { echo -e "${GREEN}[OK]${RESET}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${RESET}  $*"; }
error()   { echo -e "${RED}[ERRO]${RESET}  $*" >&2; exit 1; }
step()    { echo -e "\n${BOLD}${CYAN}▶ $*${RESET}"; }

# ── Verificações iniciais ────────────────────────────────
[[ $EUID -ne 0 ]] && error "Execute como root: sudo $0"

OS_ID=$(. /etc/os-release && echo "$ID")
OS_VERSION=$(. /etc/os-release && echo "$VERSION_ID")
[[ "$OS_ID" != "ubuntu" ]] && error "Este script é para Ubuntu. Detectado: $OS_ID"

info "Ubuntu $OS_VERSION detectado"
PHP_VERSION="8.2"
COMPOSER_VERSION="2"

# ── Variáveis de ambiente do projeto ────────────────────
PROJECT_DIR="${PROJECT_DIR:-/var/www/sweflow}"
APP_USER="${SUDO_USER:-www-data}"

echo ""
echo -e "${BOLD}╔══════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}║        Sweflow API — Instalação Ubuntu Server        ║${RESET}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════╝${RESET}"
echo ""
info "PHP:      $PHP_VERSION"
info "Composer: $COMPOSER_VERSION"
info "Projeto:  $PROJECT_DIR"
info "Usuário:  $APP_USER"
echo ""

# ── 1. Atualiza o sistema ────────────────────────────────
step "Atualizando pacotes do sistema"
apt-get update -qq
apt-get upgrade -y -qq
apt-get install -y -qq \
    ca-certificates curl gnupg lsb-release \
    software-properties-common apt-transport-https \
    git unzip zip wget nano htop ufw
success "Sistema atualizado"

# ── 2. Docker Engine ─────────────────────────────────────
step "Instalando Docker Engine"

if command -v docker &>/dev/null; then
    DOCKER_VER=$(docker --version | awk '{print $3}' | tr -d ',')
    warn "Docker já instalado: $DOCKER_VER — pulando"
else
    # Remove versões antigas
    apt-get remove -y -qq docker docker-engine docker.io containerd runc 2>/dev/null || true

    # Adiciona repositório oficial Docker
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg

    echo \
        "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
        https://download.docker.com/linux/ubuntu \
        $(lsb_release -cs) stable" \
        > /etc/apt/sources.list.d/docker.list

    apt-get update -qq
    apt-get install -y -qq \
        docker-ce docker-ce-cli containerd.io \
        docker-buildx-plugin docker-compose-plugin

    systemctl enable --now docker
    success "Docker instalado: $(docker --version)"
fi

# Adiciona usuário ao grupo docker (evita sudo)
if [[ -n "$APP_USER" && "$APP_USER" != "root" ]]; then
    usermod -aG docker "$APP_USER" 2>/dev/null || true
    info "Usuário '$APP_USER' adicionado ao grupo docker"
fi

# ── 3. Docker Compose ────────────────────────────────────
step "Verificando Docker Compose"

if docker compose version &>/dev/null; then
    success "Docker Compose plugin: $(docker compose version)"
else
    # Fallback: instala como binário standalone
    COMPOSE_LATEST=$(curl -fsSL https://api.github.com/repos/docker/compose/releases/latest \
        | grep '"tag_name"' | sed -E 's/.*"([^"]+)".*/\1/')
    curl -fsSL \
        "https://github.com/docker/compose/releases/download/${COMPOSE_LATEST}/docker-compose-$(uname -s)-$(uname -m)" \
        -o /usr/local/bin/docker-compose
    chmod +x /usr/local/bin/docker-compose
    success "Docker Compose standalone: $(docker-compose --version)"
fi

# ── 4. PHP 8.2 + extensões ──────────────────────────────
step "Instalando PHP $PHP_VERSION"

if php -v 2>/dev/null | grep -q "PHP $PHP_VERSION"; then
    success "PHP $PHP_VERSION já instalado"
else
    # Repositório Ondřej Surý (PHP mais recente para Ubuntu)
    add-apt-repository -y ppa:ondrej/php
    apt-get update -qq

    apt-get install -y -qq \
        "php${PHP_VERSION}" \
        "php${PHP_VERSION}-cli" \
        "php${PHP_VERSION}-fpm" \
        "php${PHP_VERSION}-common" \
        "php${PHP_VERSION}-curl" \
        "php${PHP_VERSION}-mbstring" \
        "php${PHP_VERSION}-xml" \
        "php${PHP_VERSION}-zip" \
        "php${PHP_VERSION}-bcmath" \
        "php${PHP_VERSION}-intl" \
        "php${PHP_VERSION}-readline" \
        "php${PHP_VERSION}-opcache" \
        "php${PHP_VERSION}-gd" \
        "php${PHP_VERSION}-fileinfo"

    # Define PHP 8.2 como padrão
    update-alternatives --set php "/usr/bin/php${PHP_VERSION}" 2>/dev/null || true

    success "PHP instalado: $(php -v | head -1)"
fi

# ── 5. Driver PostgreSQL ─────────────────────────────────
step "Instalando driver PHP para PostgreSQL (pdo_pgsql)"

if php -m | grep -q pdo_pgsql; then
    success "pdo_pgsql já habilitado"
else
    apt-get install -y -qq \
        "php${PHP_VERSION}-pgsql" \
        libpq-dev

    # Habilita a extensão
    phpenmod -v "$PHP_VERSION" pgsql pdo_pgsql 2>/dev/null || true
    success "pdo_pgsql instalado"
fi

# ── 6. Driver MySQL ──────────────────────────────────────
step "Instalando driver PHP para MySQL (pdo_mysql)"

if php -m | grep -q pdo_mysql; then
    success "pdo_mysql já habilitado"
else
    apt-get install -y -qq \
        "php${PHP_VERSION}-mysql" \
        default-libmysqlclient-dev

    phpenmod -v "$PHP_VERSION" mysql pdo_mysql mysqli 2>/dev/null || true
    success "pdo_mysql instalado"
fi

# ── 7. Composer 2 ────────────────────────────────────────
step "Instalando Composer $COMPOSER_VERSION"

if command -v composer &>/dev/null; then
    COMP_VER=$(composer --version | awk '{print $3}')
    warn "Composer já instalado: $COMP_VER — atualizando"
    composer self-update --"$COMPOSER_VERSION" 2>/dev/null || true
else
    EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

    if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then
        rm composer-setup.php
        error "Checksum do Composer inválido — instalação abortada"
    fi

    php composer-setup.php --install-dir=/usr/local/bin --filename=composer --"$COMPOSER_VERSION"
    rm composer-setup.php
    success "Composer instalado: $(composer --version)"
fi

# ── 8. Configurações de PHP para produção ───────────────
step "Ajustando configurações do PHP"

PHP_INI_CLI="/etc/php/${PHP_VERSION}/cli/php.ini"
PHP_INI_FPM="/etc/php/${PHP_VERSION}/fpm/php.ini"

configure_php_ini() {
    local ini="$1"
    [[ ! -f "$ini" ]] && return
    sed -i 's/^;*\s*expose_php\s*=.*/expose_php = Off/'           "$ini"
    sed -i 's/^;*\s*display_errors\s*=.*/display_errors = Off/'   "$ini"
    sed -i 's/^;*\s*log_errors\s*=.*/log_errors = On/'            "$ini"
    sed -i 's/^;*\s*memory_limit\s*=.*/memory_limit = 256M/'      "$ini"
    sed -i 's/^;*\s*upload_max_filesize\s*=.*/upload_max_filesize = 20M/' "$ini"
    sed -i 's/^;*\s*post_max_size\s*=.*/post_max_size = 25M/'     "$ini"
    sed -i 's/^;*\s*max_execution_time\s*=.*/max_execution_time = 60/' "$ini"
    sed -i 's/^;*\s*date\.timezone\s*=.*/date.timezone = America\/Bahia/' "$ini"
}

configure_php_ini "$PHP_INI_CLI"
configure_php_ini "$PHP_INI_FPM"

# Reinicia PHP-FPM se estiver rodando
systemctl restart "php${PHP_VERSION}-fpm" 2>/dev/null || true
success "PHP configurado"

# ── 9. Firewall básico ───────────────────────────────────
step "Configurando UFW (firewall)"

if command -v ufw &>/dev/null; then
    ufw --force enable
    ufw allow ssh
    ufw allow 80/tcp
    ufw allow 443/tcp
    ufw allow "${APP_PORT:-3005}/tcp"
    success "UFW configurado (SSH, 80, 443, ${APP_PORT:-3005})"
else
    warn "UFW não disponível — configure o firewall manualmente"
fi

# ── 10. Resumo final ─────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}${GREEN}║              Instalação concluída!                   ║${RESET}"
echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════╝${RESET}"
echo ""
echo -e "  ${BOLD}Versões instaladas:${RESET}"
echo -e "  PHP:            $(php -v | head -1 | awk '{print $1,$2}')"
echo -e "  Composer:       $(composer --version 2>/dev/null | awk '{print $1,$2,$3}')"
echo -e "  Docker:         $(docker --version | awk '{print $1,$2,$3}' | tr -d ',')"
echo -e "  Docker Compose: $(docker compose version 2>/dev/null || docker-compose --version)"
echo ""
echo -e "  ${BOLD}Extensões PHP ativas:${RESET}"
echo -e "  pdo_pgsql: $(php -m | grep -q pdo_pgsql && echo '✓' || echo '✗')"
echo -e "  pdo_mysql: $(php -m | grep -q pdo_mysql && echo '✓' || echo '✗')"
echo -e "  curl:      $(php -m | grep -q curl && echo '✓' || echo '✗')"
echo -e "  mbstring:  $(php -m | grep -q mbstring && echo '✓' || echo '✗')"
echo -e "  zip:       $(php -m | grep -q zip && echo '✓' || echo '✗')"
echo ""
echo -e "  ${BOLD}Próximos passos:${RESET}"
echo -e "  1. cd $PROJECT_DIR"
echo -e "  2. cp EXEMPLO.env .env && nano .env"
echo -e "  3. docker compose up -d postgres   ${CYAN}# sobe o banco${RESET}"
echo -e "  4. composer install"
echo -e "  5. php sweflow migrate --seed"
echo -e "  6. php -S 0.0.0.0:3005 index.php   ${CYAN}# dev${RESET}"
echo ""
if [[ -n "$APP_USER" && "$APP_USER" != "root" ]]; then
    warn "Faça logout e login novamente para usar Docker sem sudo (grupo docker)"
fi
