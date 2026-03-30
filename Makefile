# ============================================================
# Sweflow API — Makefile
# Uso: make <comando>
# ============================================================

.PHONY: help up down restart logs ps \
        up-pg up-mysql up-all \
        install migrate seed test \
        shell-pg shell-mysql \
        clean reset

# Detecta docker compose v2 ou v1
COMPOSE := $(shell docker compose version > /dev/null 2>&1 && echo "docker compose" || echo "docker-compose")

help: ## Mostra esta ajuda
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'
	@echo ""

# ── Docker ───────────────────────────────────────────────

up-pg: ## Sobe apenas PostgreSQL + Adminer
	$(COMPOSE) up -d postgres adminer

up-mysql: ## Sobe apenas MySQL + Adminer
	$(COMPOSE) up -d mysql adminer

up-all: ## Sobe todos os serviços
	$(COMPOSE) up -d

down: ## Para e remove os containers
	$(COMPOSE) down

restart: ## Reinicia todos os containers
	$(COMPOSE) restart

logs: ## Exibe logs em tempo real
	$(COMPOSE) logs -f

ps: ## Lista containers em execução
	$(COMPOSE) ps

# ── Banco de dados ───────────────────────────────────────

shell-pg: ## Abre o psql no container PostgreSQL
	$(COMPOSE) exec postgres psql -U $${DB_USUARIO:-admin} -d $${DB_NOME:-sweflow_db}

shell-mysql: ## Abre o mysql no container MySQL
	$(COMPOSE) exec mysql mysql -u $${DB_USUARIO:-admin} -p$${DB_SENHA:-123456} $${DB_NOME:-sweflow_db}

# ── Projeto ──────────────────────────────────────────────

install: ## Instala dependências PHP
	composer install

migrate: ## Executa migrations de todos os módulos
	php sweflow migrate

seed: ## Executa migrations + seeders
	php sweflow migrate --seed

test: ## Roda os testes de segurança
	@if [ -d storage/ratelimit ]; then rm -f storage/ratelimit/*.json; fi
	php tests/SecurityTest.php http://localhost:$${APP_PORT:-3005}

# ── Setup completo ───────────────────────────────────────

setup: ## Setup completo: sobe banco, instala deps, migra
	@echo "▶ Subindo PostgreSQL..."
	$(COMPOSE) up -d postgres
	@echo "▶ Aguardando banco ficar pronto..."
	@until $(COMPOSE) exec -T postgres pg_isready -U $${DB_USUARIO:-admin} > /dev/null 2>&1; do sleep 2; done
	@echo "▶ Instalando dependências..."
	composer install --no-interaction
	@echo "▶ Executando migrations..."
	php sweflow migrate --seed
	@echo ""
	@echo "✓ Setup concluído! Inicie o servidor:"
	@echo "  php -S localhost:$${APP_PORT:-3005} index.php"

# ── Limpeza ──────────────────────────────────────────────

clean: ## Remove cache e arquivos temporários
	rm -rf storage/ratelimit/*.json storage/modules_cache.php
	@echo "✓ Cache limpo"

reset: ## Para containers e remove volumes (APAGA DADOS!)
	@echo "⚠ Isso apagará todos os dados dos bancos. Confirme: [Ctrl+C para cancelar]"
	@sleep 3
	$(COMPOSE) down -v
	@echo "✓ Volumes removidos"
