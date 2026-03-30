#!/usr/bin/env bash
# ============================================================
# Sweflow API — CI/CD Security Check
# Executa testes de segurança automaticamente a cada deploy.
#
# Uso:
#   ./ci/security-check.sh [BASE_URL]
#   BASE_URL padrão: http://localhost:3005
#
# Integração GitHub Actions: ver ci/github-actions.yml
# Integração GitLab CI:      ver ci/gitlab-ci.yml
# ============================================================

set -euo pipefail

BASE_URL="${1:-${APP_URL:-http://localhost:3005}}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
TEST_FILE="$PROJECT_ROOT/tests/SecurityTest.php"

echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║   Sweflow API — CI/CD Security Check                     ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo "  URL: $BASE_URL"
echo "  PHP: $(php --version | head -1)"
echo ""

# Verifica se o PHP está disponível
if ! command -v php &> /dev/null; then
    echo "✗ PHP não encontrado. Instale PHP 8.1+"
    exit 1
fi

# Verifica se o arquivo de testes existe
if [ ! -f "$TEST_FILE" ]; then
    echo "✗ Arquivo de testes não encontrado: $TEST_FILE"
    exit 1
fi

# Limpa rate limit antes dos testes para evitar falsos positivos
RL_DIR="$PROJECT_ROOT/storage/ratelimit"
if [ -d "$RL_DIR" ]; then
    rm -f "$RL_DIR"/*.json 2>/dev/null || true
    echo "  ✓ Rate limit limpo"
fi

# Aguarda o servidor estar disponível (útil em CI com startup delay)
MAX_WAIT=30
WAITED=0
echo "  Aguardando servidor em $BASE_URL..."
while ! php -r "
    \$ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    \$r = @file_get_contents('$BASE_URL/api/status', false, \$ctx);
    exit(\$r === false ? 1 : 0);
" 2>/dev/null; do
    if [ $WAITED -ge $MAX_WAIT ]; then
        echo "  ✗ Servidor não respondeu após ${MAX_WAIT}s"
        exit 1
    fi
    sleep 2
    WAITED=$((WAITED + 2))
done
echo "  ✓ Servidor disponível"
echo ""

# Executa os testes de segurança
php "$TEST_FILE" "$BASE_URL"
EXIT_CODE=$?

echo ""
if [ $EXIT_CODE -eq 0 ]; then
    echo "  ✓ DEPLOY APROVADO — Todos os testes de segurança passaram"
else
    echo "  ✗ DEPLOY BLOQUEADO — Testes de segurança falharam"
    echo "  Corrija as vulnerabilidades antes de fazer deploy em produção."
fi
echo ""

exit $EXIT_CODE
