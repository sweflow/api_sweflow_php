# Vupi.us API

API modular em PHP com **autenticação plugável**, suporte a PostgreSQL e MySQL, HTTPS automático via Caddy e setup totalmente automatizado.

## 🚀 Características Principais

- **Autenticação Plugável** — Substitua JWT por OAuth2, SAML, LDAP ou qualquer estratégia customizada sem modificar o kernel
- **Arquitetura Modular** — Kernel + Módulos com contratos bem definidos (Ports & Adapters)
- **Multi-banco** — PostgreSQL e MySQL com suporte a duas conexões simultâneas
- **Segurança em Camadas** — Rate limiting, circuit breaker, threat scoring, audit logging, bot blocker
- **IDE Integrada** — Crie e faça deploy de módulos diretamente pela interface web
- **HTTPS Automático** — Caddy com Let's Encrypt em produção, mkcert em desenvolvimento
- **Setup com Um Comando** — Instalação completa automatizada

---

## 📦 Instalação Rápida

### Ubuntu 22.04 / 24.04

```bash
git clone https://github.com/seu-repo/api_vupi.us_php.git
cd api_vupi.us_php
sudo bash install.sh
```

Instala PHP 8.2, Docker, drivers de banco, Composer, sobe o banco via docker-compose, roda migrations e seeders, e inicia o servidor — tudo automaticamente.

### Setup Manual

```bash
composer install
php vupi setup        # menu interativo
```

Ou tudo de uma vez sem interação:

```bash
php vupi setup --auto
```

---

## 🔐 Autenticação Plugável

A Vupi.us API implementa um sistema de autenticação completamente substituível baseado em **contratos (interfaces)**. Desenvolvedores podem:

- **Substituir qualquer parte do pipeline** — token resolver, validator, user resolver, identity factory, authorization
- **Usar qualquer estratégia** — JWT (nativo), OAuth2, SAML, LDAP, sessão PHP, magic link
- **Integrar múltiplos módulos** — um módulo de auth + um módulo de users + N módulos de negócio
- **Manter compatibilidade** — módulos de negócio usam contratos, não implementações

### Exemplo: Módulo OAuth2

```php
// OAuth2Auth/OAuth2AuthProvider.php
public function boot(ContainerInterface $container): void
{
    // Substitui o TokenValidator nativo
    $container->bind(
        TokenValidatorInterface::class,
        OAuth2TokenValidator::class,
        true
    );

    // Substitui o AuthContext nativo
    $container->bind(
        AuthContextInterface::class,
        OAuth2AuthContext::class,
        true
    );
}
```

Todos os módulos que usam `Auth::user()`, `Auth::admin()`, `Auth::identity()` continuam funcionando — agora com OAuth2 em vez de JWT.

**Veja [DOC/Autenticacao.md](DOC/Autenticacao.md) para guia completo com exemplos práticos.**

---

## 🏗️ Arquitetura

```
Kernel (contratos/interfaces)
    ↓
Módulos (implementações)
    ↓
Aplicação (usa contratos)
```

**Contratos substituíveis:**
- `AuthContextInterface` — orquestrador do pipeline de auth
- `AuthorizationInterface` — decisões de permissão
- `TokenResolverInterface` — de onde vem o token?
- `TokenValidatorInterface` — o token é válido?
- `UserResolverInterface` — quem é o usuário?
- `IdentityFactoryInterface` — como montar a identidade?
- `UserRepositoryInterface` — de onde vem o usuário?
- `TokenBlacklistInterface` — como revogar tokens?

**Veja [DOC/Arquitetura.md](DOC/Arquitetura.md) para detalhes completos.**

---

## 🔧 HTTPS com Caddy

O Caddy atua como proxy reverso na frente do `php -S`, fornecendo TLS automático via Let's Encrypt em produção e certificados locais via mkcert em desenvolvimento.

### Produção (HTTPS automático)

```bash
# Via vupi.us CLI (recomendado)
php vupi setup --auto --server=pm2+caddy   # PM2 + Caddy (mais robusto)
php vupi setup --auto --caddy=production   # php -S + Caddy

# Via Makefile
make caddy-install                  # instala o Caddy (uma vez)
php -S localhost:3005 index.php &   # sobe o PHP em background
make caddy-start                    # sobe o Caddy com TLS automático
# → https://api.vupi.us funcionando com TLS
```

### Desenvolvimento local (HTTPS via mkcert)

```bash
# Via vupi.us CLI
php vupi setup --auto --db-mode=skip --caddy=dev

# Via Makefile
make caddy-dev                      # gera cert local e sobe Caddy
php -S localhost:3005 index.php     # sobe o PHP
# → https://localhost:2443 com HTTPS real
```

---

## 📚 Comandos Principais

```bash
# Migrations
php vupi migrate              # roda migrations
php vupi migrate --seed       # migrations + seeders

# Módulos
php vupi make:module Nome     # cria um novo módulo
php vupi make:plugin Nome     # cria um novo plugin

# Setup
php vupi setup --help         # ajuda completa do setup
php vupi setup --auto --server=pm2+caddy   # produção completa
php vupi setup --auto --caddy=dev          # dev com HTTPS local

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

## 📖 Documentação Completa

| Documento | Conteúdo |
|---|---|
| [Introdução](DOC/Introducao.md) | Visão geral, arquitetura e propósito |
| [Autenticação](DOC/Autenticacao.md) | **Sistema plugável, contratos, JWT, OAuth2, LDAP e exemplos práticos** |
| [Módulos](DOC/Modulos.md) | Como criar, instalar e gerenciar módulos (incluindo módulos de auth customizados) |
| [Arquitetura](DOC/Arquitetura.md) | Kernel, container, router, módulos e fluxo de requisição |
| [Middlewares](DOC/Middlewares.md) | Auth, rate limit, circuit breaker e middlewares customizados |

---

## 🧪 Testes e Qualidade

```bash
# Testes
php vendor/phpunit/phpunit/phpunit

# Análise estática (PHPStan nível 6)
php vendor/bin/phpstan analyse --level=6

# Testes de segurança
make test
```

**Status atual:**
- ✅ 897 testes passando
- ✅ PHPStan nível 6 com zero erros
- ✅ Cobertura de segurança completa (JWT, rate limit, CORS, XSS, SQL injection, etc.)

---

## 🏛️ Arquitetura Recomendada

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
  API Vupi.us (PM2)
```

---

## 📄 Licença

MIT — Vupi.us API © 2026
