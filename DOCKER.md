# Docker Setup - eScriptorium + Laravel Proxy

Configurazione Docker completa per Laravel proxy + eScriptorium con tre ambienti.

## Architettura

- **Laravel Proxy** (PHP 8.4-FPM + Bun): Frontend esposto, chiama eScriptorium internamente
- **eScriptorium**: Backend Django (interno, accessibile via nginx su porta 8082 in dev)
- **MariaDB**: Database Laravel (interno)
- **PostgreSQL**: Database eScriptorium (interno)
- **Redis**: Cache e code per entrambe le app

## Quick Start - Development

```bash
# 1. Copia file environment
cp .env.development.example .env.development
cp escriptorium/variables.env_example escriptorium/variables.env

# 2. Genera APP_KEY nel proxy/.env (se non presente)
# Assicurati che proxy/.env abbia APP_KEY=base64:... valida

# 3. Avvia tutti i servizi
docker compose -f docker-compose.development.yml up -d --build

# 4. Attendi che i container siano healthy, poi accedi
```

## Porte - Development

| Servizio | URL |
|----------|-----|
| **Laravel App** | http://localhost:8080 |
| **eScriptorium** | http://localhost:8082 |
| **phpMyAdmin** | http://localhost:8081 |
| **Flower** | http://localhost:5555 |
| **Vite HMR** | http://localhost:5173 |

## Servizi per Ambiente

| Servizio | Development | Staging | Production |
|----------|-------------|---------|------------|
| Laravel + Queue | ✓ hot reload | ✓ | ✓ |
| eScriptorium | ✓ :8082 | ✓ interno | ✓ interno |
| phpMyAdmin | ✓ :8081 | ✓ | ✗ |
| Flower | ✓ :5555 | ✓ | ✗ |
| Celery Workers | ✓ | ✓ | ✓ (multiple) |

## File Environment

- `proxy/.env` - Laravel legge questo file (deve avere APP_KEY valida!)
- `.env.development` - Variabili Docker per development
- `escriptorium/variables.env` - Configurazione eScriptorium

## Staging

```bash
cp .env.staging.example .env.staging
# Modifica con credenziali sicure
docker compose -f docker-compose.staging.yml up -d --build
```

## Production

```bash
cp .env.production.example .env.production
# Modifica con credenziali sicure
docker compose -f docker-compose.production.yml up -d --build
```

## Comandi Utili

```bash
# Logs
docker compose -f docker-compose.development.yml logs -f

# Laravel artisan
docker compose -f docker-compose.development.yml exec proxy-php php artisan <cmd>

# Generare nuova APP_KEY
docker compose -f docker-compose.development.yml exec proxy-php php artisan key:generate --show

# eScriptorium collectstatic
docker compose -f docker-compose.development.yml exec escriptorium-web python manage.py collectstatic

# Ricostruire un servizio
docker compose -f docker-compose.development.yml build --no-cache <service>

# Fermare tutto
docker compose -f docker-compose.development.yml down
```

## Note ARM64 (Apple Silicon)

I servizi vengono buildati localmente per supportare ARM64. Alcuni container (eScriptorium, Flower) mostrano warning "AMD64" ma funzionano via emulazione Rosetta.

## GPU Support (Production)

Per abilitare GPU su Celery, modifica `docker-compose.production.yml`:
1. Decommenta variabili NVIDIA in `celery-gpu`
2. Decommenta `runtime: nvidia`
3. Decommenta device reservations GPU
