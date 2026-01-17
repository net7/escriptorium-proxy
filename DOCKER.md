# Docker Setup - eScriptorium + Laravel Proxy

This project uses Docker Compose to run the full stack with three environment configurations.

## Architecture

- **Laravel Proxy** (PHP 8.4-FPM): Routes external requests, calls eScriptorium internally
- **eScriptorium**: Django app (NOT exposed externally - internal only)
- **MariaDB**: Laravel database
- **PostgreSQL**: eScriptorium database
- **Redis**: Caching and queues for both apps
- **Nginx**: Reverse proxy (only exposes Laravel)

## Quick Start

### Development (with hot reload)

```bash
# Copy environment file
cp .env.development.example .env.development

# Copy eScriptorium variables
cp escriptorium/variables.env_example escriptorium/variables.env

# Start all services
docker compose -f docker-compose.development.yml up -d

# Run Laravel migrations
docker compose -f docker-compose.development.yml exec proxy-php php artisan migrate

# Generate app key
docker compose -f docker-compose.development.yml exec proxy-php php artisan key:generate
```

**Access points:**
- Application: http://localhost
- phpMyAdmin: http://localhost:8081
- Vite HMR: http://localhost:5173
- Flower: http://localhost:5555

### Staging

```bash
cp .env.staging.example .env.staging
# Edit .env.staging with your credentials

docker compose -f docker-compose.staging.yml up -d --build
```

### Production

```bash
cp .env.production.example .env.production
# Edit .env.production with secure credentials

docker compose -f docker-compose.production.yml up -d --build
```

## Services Overview

| Service | Development | Staging | Production |
|---------|-------------|---------|------------|
| Laravel App | ✓ Hot reload | ✓ | ✓ |
| Queue Worker | ✓ | ✓ | ✓ (2 replicas) |
| phpMyAdmin | ✓ :8081 | ✓ :8081 | ✗ |
| Flower | ✓ :5555 | ✓ :5555 | ✗ |
| eScriptorium | ✓ internal | ✓ internal | ✓ internal |

## Hot Reload (Development)

Laravel hot reload works automatically via:
- Volume mounting of `./proxy` directory
- Bun Vite dev server on port 5173
- PHP-FPM with development configuration

To watch for changes:
```bash
# Vite is already running, but if you need to restart:
docker compose -f docker-compose.development.yml restart proxy-vite
```

## GPU Support (Production)

To enable GPU for Kraken training, edit `docker-compose.production.yml`:

1. Uncomment the NVIDIA environment variables in `celery-gpu`
2. Uncomment `runtime: nvidia`
3. Uncomment GPU device reservations

## Useful Commands

```bash
# View logs
docker compose -f docker-compose.development.yml logs -f

# Laravel artisan
docker compose -f docker-compose.development.yml exec proxy-php php artisan <command>

# Access MariaDB CLI
docker compose -f docker-compose.development.yml exec mariadb mysql -u laravel -p

# Rebuild a specific service
docker compose -f docker-compose.development.yml build --no-cache proxy-php
```
