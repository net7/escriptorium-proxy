.PHONY: setup dev staging production stop logs clean help

# Default environment
ENV ?= development

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

setup: ## Run initial setup script
	@./scripts/setup.sh

# ===========================================
# DEVELOPMENT
# ===========================================
dev: setup ## Start development environment
	docker compose -f docker-compose.development.yml up -d --build

dev-logs: ## Show development logs
	docker compose -f docker-compose.development.yml logs -f

dev-stop: ## Stop development environment
	docker compose -f docker-compose.development.yml down

dev-restart: ## Restart development environment
	docker compose -f docker-compose.development.yml restart

dev-shell: ## Open shell in Laravel container
	docker compose -f docker-compose.development.yml exec proxy-php sh

dev-artisan: ## Run artisan command (usage: make dev-artisan cmd="migrate")
	docker compose -f docker-compose.development.yml exec proxy-php php artisan $(cmd)

dev-tinker: ## Open Laravel Tinker
	docker compose -f docker-compose.development.yml exec proxy-php php artisan tinker

# ===========================================
# STAGING
# ===========================================
staging: setup ## Start staging environment
	@if [ ! -f .env.staging ]; then \
		echo "Creating .env.staging from example..."; \
		cp .env.staging.example .env.staging; \
		echo "Please edit .env.staging with your settings before deploying!"; \
		exit 1; \
	fi
	docker compose -f docker-compose.staging.yml up -d --build

staging-logs: ## Show staging logs
	docker compose -f docker-compose.staging.yml logs -f

staging-stop: ## Stop staging environment
	docker compose -f docker-compose.staging.yml down

# ===========================================
# PRODUCTION
# ===========================================
production: setup ## Start production environment
	@if [ ! -f .env.production ]; then \
		echo "Creating .env.production from example..."; \
		cp .env.production.example .env.production; \
		echo "Please edit .env.production with your settings before deploying!"; \
		exit 1; \
	fi
	docker compose -f docker-compose.production.yml up -d --build

production-logs: ## Show production logs
	docker compose -f docker-compose.production.yml logs -f

production-stop: ## Stop production environment
	docker compose -f docker-compose.production.yml down

# ===========================================
# UTILITIES
# ===========================================
ps: ## Show running containers
	docker compose -f docker-compose.$(ENV).yml ps

logs: ## Show logs (usage: make logs ENV=development)
	docker compose -f docker-compose.$(ENV).yml logs -f

stop: ## Stop environment (usage: make stop ENV=development)
	docker compose -f docker-compose.$(ENV).yml down

clean: ## Remove all containers, volumes, and images
	docker compose -f docker-compose.development.yml down -v --rmi local 2>/dev/null || true
	docker compose -f docker-compose.staging.yml down -v --rmi local 2>/dev/null || true
	docker compose -f docker-compose.production.yml down -v --rmi local 2>/dev/null || true
	@echo "Cleaned up all Docker resources"

clean-volumes: ## Remove only volumes (WARNING: deletes all data)
	@echo "WARNING: This will delete all database data!"
	@read -p "Are you sure? [y/N] " confirm && [ "$$confirm" = "y" ] || exit 1
	docker compose -f docker-compose.$(ENV).yml down -v

rebuild: ## Rebuild containers without cache
	docker compose -f docker-compose.$(ENV).yml build --no-cache
	docker compose -f docker-compose.$(ENV).yml up -d

# ===========================================
# DATABASE
# ===========================================
db-migrate: ## Run Laravel migrations
	docker compose -f docker-compose.$(ENV).yml exec proxy-php php artisan migrate

db-seed: ## Run Laravel seeders
	docker compose -f docker-compose.$(ENV).yml exec proxy-php php artisan db:seed

db-fresh: ## Fresh migrate with seeders
	docker compose -f docker-compose.$(ENV).yml exec proxy-php php artisan migrate:fresh --seed

# ===========================================
# QUEUE
# ===========================================
queue-restart: ## Restart queue workers
	docker compose -f docker-compose.$(ENV).yml restart proxy-queue

celery-restart: ## Restart Celery workers
	docker compose -f docker-compose.$(ENV).yml restart celery-main celery-gpu celery-low-priority celery-live
