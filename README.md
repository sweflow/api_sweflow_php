# Sweflow API

API modular em PHP com autenticação JWT, suporte a PostgreSQL e MySQL, HTTPS automático via Caddy e setup totalmente automatizado.

## Instalação rápida (um comando)

> Ubuntu 22.04 / 24.04

```bash
git clone https://github.com/seu-repo/api_sweflow_php.git
cd api_sweflow_php
sudo bash install.sh
```

Instala PHP 8.2, Docker, drivers de banco, Composer, sobe o banco via docker-compose, roda migrations e seeders, e inicia o servidor — tudo automaticamente.

---

## Setup manual

```bash
composer install
php sweflow setup        # menu interativo
```

Ou tudo de uma vez sem interação:

```bash
php sweflow setup --auto
```

---

## HTTPS com Caddy

O Caddy atua como proxy reverso na frente do `php -S`, fornecendo TLS automático via Let's Encrypt em produção e certificados locais via mkcert em desenvolvimento.

### Produção (HTTPS automático)

```bash
# Via Makefile
make caddy-install                  # instala o Caddy (uma vez)
php -S localhost:3005 index.php &   # sobe o PHP em background
make caddy-start                    # sobe o Caddy com TLS automático
# → https://api.typper.shop funcionando com TLS

# Via sweflow CLI (recomendado — faz tudo de uma vez)
php sweflow setup --auto --server=pm2+caddy   # PM2 + Caddy (mais robusto)
php sweflow setup --auto --caddy=production   # php -S + Caddy
```

### Desenvolvimento local (HTTPS via mkcert)

```bash
# Via Makefile
make caddy-dev                      # gera cert local e sobe Caddy
php -S localhost:3005 index.php     # sobe o PHP
# → https://localhost:2443 com HTTPS real

# Via sweflow CLI
php sweflow setup --auto --db-mode=skip --caddy=dev
```

### Menu interativo

```bash
php sweflow setup
# Opção 14 → Instalar Caddy + subir HTTPS em produção
# Opção 15 → Subir Caddy em desenvolvimento (HTTPS local via mkcert)
# Opção 16 → Subir PM2 + Caddy em produção (recomendado)
```

---

## Arquitetura recomendada

```
Hoje (fase atual):
  Caddy (TLS automático) → php -S / PM2

Amanhã (quando crescer):
  Cloudflare
      ↓
  Caddy (TLS + proxy simples)
      ↓
  Nginx (roteamento interno)
      ↓
  API Sweflow (PM2)
```

---

## Comandos principais

```bash
# Migrations
php sweflow migrate              # roda migrations
php sweflow migrate --seed       # migrations + seeders

# Módulos
php sweflow make:module Nome     # cria um novo módulo
php sweflow make:plugin Nome     # cria um novo plugin

# Setup
php sweflow setup --help         # ajuda completa do setup
php sweflow setup --auto --server=pm2+caddy   # produção completa
php sweflow setup --auto --caddy=dev          # dev com HTTPS local

# Makefile
make caddy-install   # instala o Caddy
make caddy-start     # sobe Caddy em produção
make caddy-stop      # para o Caddy
make caddy-reload    # recarrega config sem downtime
make caddy-dev       # HTTPS local com mkcert
make test            # roda testes de segurança
```

### Flags do `--auto`

| Flag | Valores | Padrão | Descrição |
|------|---------|--------|-----------|
| `--db-mode` | `compose`, `docker`, `skip` | `compose` | Como subir o banco |
| `--server` | `background`, `php`, `pm2`, `pm2+caddy` | `background` | Como subir o servidor |
| `--caddy` | `production`, `dev`, `skip` | `skip` | Proxy HTTPS |
| `--jwt` | `if-empty`, `skip` | `if-empty` | Geração de secrets JWT |
| `--api-token` | `generate`, `skip` | `skip` | Gera token JWT de API |

---

## Documentação completa

Veja [Documentação.md](Documentação.md) para guia completo de uso, criação de módulos, segurança e referência de comandos.

## Licença

MIT — Sweflow API © 2026
