#!/bin/sh
set -e

echo "🚀 Starting Laravel Proxy Setup..."

# Function to wait for database
wait_for_db() {
    echo "⏳ Waiting for database connection..."
    maxTries=30
    while [ $maxTries -gt 0 ]; do
        if php artisan db:monitor 2>/dev/null; then
            echo "✅ Database is ready!"
            return 0
        fi
        maxTries=$((maxTries - 1))
        echo "   Retrying... ($maxTries attempts left)"
        sleep 2
    done
    echo "❌ Could not connect to database"
    exit 1
}

# Run composer install if vendor is empty or missing
if [ ! -d "vendor" ] || [ -z "$(ls -A vendor 2>/dev/null)" ]; then
    echo "📦 Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Run bun install if node_modules is empty or missing
if [ ! -d "node_modules" ] || [ -z "$(ls -A node_modules 2>/dev/null)" ]; then
    echo "📦 Installing Bun dependencies..."
    bun install
fi

# Build assets for production/staging
if [ "$APP_ENV" = "production" ] || [ "$APP_ENV" = "staging" ]; then
    if [ ! -d "public/build" ] || [ -z "$(ls -A public/build 2>/dev/null)" ]; then
        echo "🔨 Building assets..."
        bun run build
    fi
fi

# Wait for database
wait_for_db

# Run migrations
echo "🗄️  Running migrations..."
php artisan migrate --force

# Create storage link if not exists
if [ ! -L "public/storage" ]; then
    echo "🔗 Creating storage link..."
    php artisan storage:link 2>/dev/null || true
fi

# Clear and cache config for production/staging
if [ "$APP_ENV" = "production" ] || [ "$APP_ENV" = "staging" ]; then
    echo "⚡ Caching configuration..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

echo "✅ Laravel Proxy is ready!"

# Execute the main command (php-fpm or queue worker)
exec "$@"
