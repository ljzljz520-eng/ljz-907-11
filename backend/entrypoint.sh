#!/bin/sh

# Wait for database to be ready (check both port and actual connection)
echo "Waiting for database..."
until nc -z db 3306; do
  sleep 1
done
echo "Database port is open, testing connection..."

# Wait for MySQL to be fully ready using PHP (more reliable)
RETRIES=30
until php -r "
try {
    \$pdo = new PDO('mysql:host=db;port=3306', '1', '1');
    \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    \$pdo->exec('SELECT 1');
    exit(0);
} catch (Exception \$e) {
    exit(1);
}
" 2>/dev/null || [ $RETRIES -eq 0 ]; do
  echo "Waiting for MySQL to be ready... ($RETRIES retries left)"
  RETRIES=$((RETRIES-1))
  sleep 2
done

if [ $RETRIES -eq 0 ]; then
  echo "ERROR: MySQL is not ready after 60 seconds"
  exit 1
fi

echo "Database is ready!"

# Ensure .env file exists with correct database configuration
if [ ! -f ".env" ]; then
  echo "Creating .env file from .env.example..."
  cp .env.example .env 2>/dev/null || true
fi

# Force update database configuration in .env (always override)
echo "Configuring database connection..."
# Remove existing DB_* lines and add new ones
sed -i '/^DB_HOST=/d' .env 2>/dev/null || true
sed -i '/^DB_DATABASE=/d' .env 2>/dev/null || true
sed -i '/^DB_USERNAME=/d' .env 2>/dev/null || true
sed -i '/^DB_PASSWORD=/d' .env 2>/dev/null || true
sed -i '/^DB_CONNECTION=/d' .env 2>/dev/null || true
sed -i '/^DB_PORT=/d' .env 2>/dev/null || true

# Add correct database configuration
echo "DB_CONNECTION=mysql" >> .env
echo "DB_HOST=db" >> .env
echo "DB_PORT=3306" >> .env
echo "DB_DATABASE=1" >> .env
echo "DB_USERNAME=1" >> .env
echo "DB_PASSWORD=1" >> .env

echo "Database configuration updated in .env"
echo "Verifying .env database config:"
grep "^DB_" .env | head -6 || echo "Warning: Could not verify DB config in .env"

# Clear config cache immediately after .env update to ensure fresh config
echo "Clearing old configuration cache..."
php artisan config:clear 2>/dev/null || true

# Ensure required directories exist and are writable
echo "Creating required directories..."
mkdir -p bootstrap/cache
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/logs
chmod -R 775 bootstrap/cache storage
chown -R www-data:www-data bootstrap/cache storage 2>/dev/null || true

# Install dependencies if vendor/autoload.php is missing
if [ ! -f "vendor/autoload.php" ]; then
  echo "Installing Composer dependencies..."
  composer install --no-interaction --optimize-autoloader
fi

# Clear and rebuild config cache to ensure .env changes are picked up
# This ensures CORS and other configs are ready before first request
echo "Rebuilding configuration cache..."
php artisan config:clear 2>/dev/null || true
# Cache config to ensure CORS headers are ready on first request
# If caching fails, Laravel will load config from files on each request (slower but works)
php artisan config:cache 2>/dev/null || {
  echo "Warning: Config cache failed, using file-based config (slower but functional)"
}

# Verify CORS configuration is cached
echo "Verifying CORS configuration in cache..."
php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$config = \$app->make('config')->get('cors', []);
if (empty(\$config) || !isset(\$config['allowed_origins'])) {
    echo \"ERROR: CORS configuration not found in cache!\\n\";
    exit(1);
}
echo \"✓ CORS configuration verified: \" . count(\$config['allowed_origins']) . \" allowed origin(s)\\n\";
" || {
  echo "Warning: Could not verify CORS config, but continuing..."
}

# Run migrations and seed
php artisan migrate --force
php artisan db:seed --force

# Warm up the application by initializing the kernel and loading config
# This ensures the config cache is fully loaded before PHP-FPM starts
# We do this AFTER migrations/seed so database is ready, but BEFORE PHP-FPM starts
echo "Warming up application (preloading config cache)..."
php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
// Boot the application to ensure all service providers are registered
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
// Verify CORS config is accessible
\$corsConfig = \$app->make('config')->get('cors', []);
if (!empty(\$corsConfig) && isset(\$corsConfig['allowed_origins'])) {
    echo \"✓ Application warmed up - CORS config loaded: \" . count(\$corsConfig['allowed_origins']) . \" origin(s)\\n\";
} else {
    echo \"⚠ Warning: CORS config not found after warmup\\n\";
}
" 2>/dev/null || {
  echo "Warning: Could not warm up application, but config should still be cached..."
}

# Start PHP-FPM
echo "Starting PHP-FPM..."
php-fpm
