#!/bin/bash
# Instala e configura Fail2Ban para a Vupi.us API
set -e

echo "==> Instalando Fail2Ban..."
apt-get update -qq && apt-get install -y fail2ban

echo "==> Criando diretório de log da aplicação..."
mkdir -p /var/log/vupi.us
chmod 755 /var/log/vupi.us

echo "==> Copiando configurações..."
cp "$(dirname "$0")/jail.local"                          /etc/fail2ban/jail.local
cp "$(dirname "$0")/filter.d/vupi.us-nginx.conf"         /etc/fail2ban/filter.d/vupi.us-nginx.conf
cp "$(dirname "$0")/filter.d/vupi.us-nginx-auth.conf"    /etc/fail2ban/filter.d/vupi.us-nginx-auth.conf
cp "$(dirname "$0")/filter.d/vupi.us-api.conf"           /etc/fail2ban/filter.d/vupi.us-api.conf
cp "$(dirname "$0")/filter.d/vupi.us-api-ratelimit.conf" /etc/fail2ban/filter.d/vupi.us-api-ratelimit.conf
cp "$(dirname "$0")/filter.d/vupi.us-bot.conf"           /etc/fail2ban/filter.d/vupi.us-bot.conf

echo "==> Configurando redirecionamento de log do PHP para /var/log/vupi.us/app.log..."
# Se usar PM2: pm2 logs são capturados automaticamente
# Se usar php -S: redirecione stderr para o arquivo de log
# Exemplo no Makefile/systemd: php -S 0.0.0.0:3005 index.php 2>> /var/log/vupi.us/app.log

echo "==> Reiniciando Fail2Ban..."
systemctl enable fail2ban
systemctl restart fail2ban

echo ""
echo "==> Status dos jails:"
fail2ban-client status

echo ""
echo "==> Instalação concluída."
echo "    Para verificar um jail específico: sudo fail2ban-client status vupi.us-api-brute"
echo "    Para desbanir um IP: sudo fail2ban-client set vupi.us-api-brute unbanip <IP>"
