#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

echo "=== eScriptorium Proxy Setup ==="

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# ----------------------------------------------
# 0. Detect platform automatically
# ----------------------------------------------
detect_platform() {
    local arch=$(uname -m)
    case "$arch" in
        arm64|aarch64)
            echo "linux/arm64"
            ;;
        x86_64|amd64)
            echo "linux/amd64"
            ;;
        *)
            echo "linux/amd64"  # Default fallback
            ;;
    esac
}

PLATFORM=$(detect_platform)
echo -e "${BLUE}Detected platform: ${PLATFORM}${NC}"

# ----------------------------------------------
# 1. Configure platform in docker-compose files
# ----------------------------------------------
configure_platform() {
    local file="$1"
    local platform="$2"

    if [ -f "$file" ]; then
        # Replace any platform specification with the detected one
        if grep -q "platform: linux/" "$file"; then
            sed -i.bak "s|platform: linux/arm64|platform: ${platform}|g" "$file"
            sed -i.bak "s|platform: linux/amd64|platform: ${platform}|g" "$file"
            rm -f "$file.bak"
            echo -e "${GREEN}Configured platform in $(basename "$file")${NC}"
        fi
    fi
}

echo -e "${YELLOW}Configuring Docker platform...${NC}"
configure_platform "$ROOT_DIR/docker-compose.development.yml" "$PLATFORM"

# ----------------------------------------------
# 2. Setup eScriptorium variables.env
# ----------------------------------------------
ESCRIPTORIUM_ENV="$ROOT_DIR/escriptorium/variables.env"
ESCRIPTORIUM_ENV_EXAMPLE="$ROOT_DIR/escriptorium/variables.env_example"

if [ ! -f "$ESCRIPTORIUM_ENV" ]; then
    echo -e "${YELLOW}Creating escriptorium/variables.env from template...${NC}"
    cp "$ESCRIPTORIUM_ENV_EXAMPLE" "$ESCRIPTORIUM_ENV"

    # Apply necessary modifications for our Docker setup
    echo -e "${YELLOW}Applying configuration for Docker setup...${NC}"

    # Fix SQL_HOST: db -> postgres (our container name)
    sed -i.bak 's/^SQL_HOST=db$/SQL_HOST=postgres/' "$ESCRIPTORIUM_ENV"

    # Enable USE_X_FORWARDED_HOST for proxy setup
    sed -i.bak 's/^# USE_X_FORWARDED_HOST=True$/USE_X_FORWARDED_HOST=True/' "$ESCRIPTORIUM_ENV"

    # Add CSRF trusted origins for Laravel proxy
    sed -i.bak 's|^CSRF_TRUSTED_ORIGINS=.*$|CSRF_TRUSTED_ORIGINS=http://localhost:8080,http://localhost:8082|' "$ESCRIPTORIUM_ENV"

    # Enable TEI XML export
    sed -i.bak 's/^# EXPORT_TEI_XML=true$/EXPORT_TEI_XML=true/' "$ESCRIPTORIUM_ENV"

    # Add Celery concurrency settings if not present
    if ! grep -q "^CELERY_MAIN_CONC=" "$ESCRIPTORIUM_ENV"; then
        echo "" >> "$ESCRIPTORIUM_ENV"
        echo "# Celery worker concurrency" >> "$ESCRIPTORIUM_ENV"
        echo "CELERY_MAIN_CONC=2" >> "$ESCRIPTORIUM_ENV"
        echo "CELERY_LOW_CONC=2" >> "$ESCRIPTORIUM_ENV"
        echo "CELERY_LIVE_CONC=2" >> "$ESCRIPTORIUM_ENV"
    fi

    # Cleanup backup files
    rm -f "$ESCRIPTORIUM_ENV.bak"

    echo -e "${GREEN}Created escriptorium/variables.env${NC}"
else
    echo -e "${GREEN}escriptorium/variables.env already exists${NC}"
fi

# ----------------------------------------------
# 3. Setup Laravel Proxy .env (from root .env.development)
# ----------------------------------------------
PROXY_ENV="$ROOT_DIR/proxy/.env"
ROOT_ENV="$ROOT_DIR/.env.development"

if [ ! -f "$PROXY_ENV" ] && [ -f "$ROOT_ENV" ]; then
    echo -e "${YELLOW}Copying .env.development to proxy/.env...${NC}"
    cp "$ROOT_ENV" "$PROXY_ENV"
    echo -e "${GREEN}Created proxy/.env${NC}"
elif [ -f "$PROXY_ENV" ]; then
    echo -e "${GREEN}proxy/.env already exists${NC}"
fi

# ----------------------------------------------
# 4. Create necessary directories
# ----------------------------------------------
echo -e "${YELLOW}Creating necessary directories...${NC}"

mkdir -p "$ROOT_DIR/docker/nginx/ssl"
mkdir -p "$ROOT_DIR/docker/pgadmin"

# Create pgpass file for pgAdmin if not exists
PGPASS_FILE="$ROOT_DIR/docker/pgadmin/pgpass"
if [ ! -f "$PGPASS_FILE" ]; then
    echo "postgres:5432:escriptorium:postgres:postgres" > "$PGPASS_FILE"
    chmod 600 "$PGPASS_FILE"
    echo -e "${GREEN}Created pgpass file${NC}"
fi

# Create servers.json for pgAdmin if not exists
SERVERS_JSON="$ROOT_DIR/docker/pgadmin/servers.json"
if [ ! -f "$SERVERS_JSON" ]; then
    cat > "$SERVERS_JSON" << 'EOF'
{
    "Servers": {
        "1": {
            "Name": "eScriptorium",
            "Group": "Servers",
            "Host": "postgres",
            "Port": 5432,
            "MaintenanceDB": "escriptorium",
            "Username": "postgres",
            "PassFile": "/pgadmin4/pgpass",
            "SSLMode": "prefer"
        }
    }
}
EOF
    echo -e "${GREEN}Created pgAdmin servers.json${NC}"
fi

# ----------------------------------------------
# 5. Initialize git submodule if needed
# ----------------------------------------------
if [ ! -f "$ROOT_DIR/escriptorium/Dockerfile" ]; then
    echo -e "${YELLOW}Initializing git submodule...${NC}"
    cd "$ROOT_DIR"
    git submodule update --init --recursive
    echo -e "${GREEN}Submodule initialized${NC}"
fi

# ----------------------------------------------
# 6. Summary
# ----------------------------------------------
echo ""
echo -e "${GREEN}=== Setup Complete ===${NC}"
echo ""
echo -e "Platform: ${BLUE}${PLATFORM}${NC}"
echo ""
echo "Next steps:"
echo "  1. Review and customize escriptorium/variables.env if needed"
echo "  2. Review and customize .env.development if needed"
echo "  3. Start the containers:"
echo ""
echo "     # Development"
echo "     docker compose -f docker-compose.development.yml up -d --build"
echo ""
echo "     # Staging"
echo "     cp .env.staging.example .env.staging"
echo "     docker compose -f docker-compose.staging.yml up -d --build"
echo ""
echo "     # Production"
echo "     cp .env.production.example .env.production"
echo "     docker compose -f docker-compose.production.yml up -d --build"
echo ""
echo "Access points (Development):"
echo "  - Laravel Proxy:    http://localhost:8080"
echo "  - eScriptorium:     http://localhost:8082"
echo "  - phpMyAdmin:       http://localhost:8081"
echo "  - pgAdmin:          http://localhost:5050"
echo "  - Flower:           http://localhost:5555"
echo "  - Vite Dev Server:  http://localhost:5173"
