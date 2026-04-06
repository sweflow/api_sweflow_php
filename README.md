# Sweflow API

API modular em PHP com autenticaÃ§Ã£o JWT, suporte a PostgreSQL e MySQL, HTTPS automÃ¡tico via Caddy e setup totalmente automatizado.

## InstalaÃ§Ã£o rÃ¡pida (um comando)

> Ubuntu 22.04 / 24.04

```bash
git clone https://github.com/seu-repo/api_sweflow_php.git
cd api_sweflow_php
sudo bash install.sh
```

Instala PHP 8.2, Docker, drivers de banco, Composer, sobe o banco via docker-compose, roda migrations e seeders, e inicia o servidor â€” tudo automaticamente.

---

## Setup manual

```bash
composer install
php sweflow setup        # menu interativo
```

Ou tudo de uma vez sem interaÃ§Ã£o:

```bash
php sweflow setup --auto
```

---

## HTTPS com Caddy

O Caddy atua como proxy reverso na frente do `php -S`, fornecendo TLS automÃ¡tico via Let's Encrypt em produÃ§Ã£o e certificados locais via mkcert em desenvolvimento.

### ProduÃ§Ã£o (HTTPS automÃ¡tico)

```bash
# Via Makefile
make caddy-install                  # instala o Caddy (uma vez)
php -S localhost:3005 index.php &   # sobe o PHP em background
make caddy-start                    # sobe o Caddy com TLS automÃ¡tico
# â†’ https://api.vupi.us funcionando com TLS

# Via sweflow CLI (recomendado â€” faz tudo de uma vez)
php sweflow setup --auto --server=pm2+caddy   # PM2 + Caddy (mais robusto)
php sweflow setup --auto --caddy=production   # php -S + Caddy
```

### Desenvolvimento local (HTTPS via mkcert)

```bash
# Via Makefile
make caddy-dev                      # gera cert local e sobe Caddy
php -S localhost:3005 index.php     # sobe o PHP
# â†’ https://localhost:2443 com HTTPS real

# Via sweflow CLI
php sweflow setup --auto --db-mode=skip --caddy=dev
```

### Menu interativo

```bash
php sweflow setup
# OpÃ§Ã£o 14 â†’ Instalar Caddy + subir HTTPS em produÃ§Ã£o
# OpÃ§Ã£o 15 â†’ Subir Caddy em desenvolvimento (HTTPS local via mkcert)
# OpÃ§Ã£o 16 â†’ Subir PM2 + Caddy em produÃ§Ã£o (recomendado)
```

---

## Arquitetura recomendada

```
Hoje (fase atual):
  Caddy (TLS automÃ¡tico) â†’ php -S / PM2

AmanhÃ£ (quando crescer):
  Cloudflare
      â†“
  Caddy (TLS + proxy simples)
      â†“
  Nginx (roteamento interno)
      â†“
  API Sweflow (PM2)
```

---

## Comandos principais

```bash
# Migrations
php sweflow migrate              # roda migrations
php sweflow migrate --seed       # migrations + seeders

# MÃ³dulos
php sweflow make:module Nome     # cria um novo mÃ³dulo
php sweflow make:plugin Nome     # cria um novo plugin

# Setup
php sweflow setup --help         # ajuda completa do setup
php sweflow setup --auto --server=pm2+caddy   # produÃ§Ã£o completa
php sweflow setup --auto --caddy=dev          # dev com HTTPS local

# Makefile
make caddy-install   # instala o Caddy
make caddy-start     # sobe Caddy em produÃ§Ã£o
make caddy-stop      # para o Caddy
make caddy-reload    # recarrega config sem downtime
make caddy-dev       # HTTPS local com mkcert
make test            # roda testes de seguranÃ§a
```

### Flags do `--auto`

| Flag | Valores | PadrÃ£o | DescriÃ§Ã£o |
|------|---------|--------|-----------|
| `--db-mode` | `compose`, `docker`, `skip` | `compose` | Como subir o banco |
| `--server` | `background`, `php`, `pm2`, `pm2+caddy` | `background` | Como subir o servidor |
| `--caddy` | `production`, `dev`, `skip` | `skip` | Proxy HTTPS |
| `--jwt` | `if-empty`, `skip` | `if-empty` | GeraÃ§Ã£o de secrets JWT |
| `--api-token` | `generate`, `skip` | `skip` | Gera token JWT de API |

---

## DocumentaÃ§Ã£o completa

Veja [DocumentaÃ§Ã£o.md](DocumentaÃ§Ã£o.md) para guia completo de uso, criaÃ§Ã£o de mÃ³dulos, seguranÃ§a e referÃªncia de comandos.

## LicenÃ§a

MIT â€” Sweflow API Â© 2026
