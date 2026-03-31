#!/usr/bin/env bash
# ============================================================
# Sweflow API — Configuração automática do Nginx + SSL
#
# Uso:
#   sudo bash scripts/setup-nginx.sh api.typper.shop /var/www/sweflow
# ============================================================

set -euo pipefail

DOMAIN="${1:-api.typper.shop}"
PROJECT_DIR="${2:-/var/www/sweflow}"
APP_PORT="${APP_PORT:-3005}"

[[ $EUID -ne 0 ]] && { echo "Execute como root: sudo $0 $*"; exit 1; }

echo ""
echo "▶ Configurando Nginx para $DOMAIN → porta $APP_PORT"

# Instala Nginx e Certbot se necessário
apt-get install -y -qq nginx certbot python3-certbot-nginx

# Cria configuração do site
CONF="/etc/nginx/sites-available/$DOMAIN"
cat > "$CONF" <<NGINX
server {
    listen 80;
    server_name $DOMAIN;
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl http2;
    server_name $DOMAIN;

    ssl_certificate     /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;
    include             /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options DENY always;
    add_header X-Content-Type-Options nosniff always;

    location / {
        proxy_pass         http://127.0.0.1:$APP_PORT;
        proxy_http_version 1.1;
        proxy_set_header Host              \$host;
        proxy_set_header X-Real-IP         \$remote_addr;
        proxy_set_header X-Forwarded-For   \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host  \$host;
        proxy_connect_timeout 60s;
        proxy_send_timeout    60s;
        proxy_read_timeout    60s;
        client_max_body_size  20M;
    }
}
NGINX

# Ativa o site
ln -sf "$CONF" "/etc/nginx/sites-enabled/$DOMAIN"
rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true

# Testa configuração
nginx -t

# Obtém certificado SSL (Let's Encrypt)
echo ""
echo "▶ Obtendo certificado SSL para $DOMAIN..."
certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos \
    --email "admin@$DOMAIN" --redirect || {
    echo "⚠ Certbot falhou. Configure o SSL manualmente."
    echo "  certbot --nginx -d $DOMAIN"
}

systemctl reload nginx
echo ""
echo "✓ Nginx configurado: https://$DOMAIN → http://127.0.0.1:$APP_PORT"
echo ""
echo "Próximo passo: atualize o .env com:"
echo "  APP_URL=https://$DOMAIN"
echo "  COOKIE_SECURE=true"
echo "  COOKIE_SAMESITE=Lax"
echo "  JWT_ISSUER=https://$DOMAIN"
echo "  JWT_AUDIENCE=https://$DOMAIN"
