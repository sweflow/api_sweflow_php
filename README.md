# Sweflow API

API modular em PHP com autenticação JWT, suporte a PostgreSQL e MySQL, e setup totalmente automatizado.

## Instalação rápida (um comando)

> Ubuntu 22.04 / 24.04

```bash
git clone https://github.com/seu-repo/api_sweflow_php.git
cd api_sweflow_php
sudo bash install.sh
```

Isso instala PHP 8.2, Docker, drivers de banco, Composer, sobe o banco via docker-compose, roda migrations e seeders, e inicia o servidor — tudo automaticamente.

## Instalação manual

```bash
composer install
php sweflow setup        # menu interativo
```

Ou tudo de uma vez sem interação:

```bash
php sweflow setup --auto
```

## Comandos principais

```bash
php sweflow migrate          # roda migrations
php sweflow migrate --seed   # migrations + seeders
php sweflow make:module Nome # cria um novo módulo
php sweflow setup --help     # ajuda do setup
```

## Documentação completa

Veja [Documentação.md](Documentação.md) para guia completo de uso, criação de módulos, segurança e referência de comandos.

## Licença

MIT — Sweflow API © 2026
