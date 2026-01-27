#!/bin/bash

# Set default values for environment variables if not set
export APP_ENV=${APP_ENV:-"production"}
export LOG_LEVEL=${LOG_LEVEL:-"info"}
export LOG_PATH=${LOG_PATH:-"/var/log"}
export LOG_ROTATION_DAYS=${LOG_ROTATION_DAYS:-"7"}

# Create log directory if it doesn't exist
mkdir -p "${LOG_PATH}"
chown -R www-data:www-data "${LOG_PATH}"

# Create cache directory if it doesn't exist
mkdir -p /app/cache
chown -R www-data:www-data /app/cache

# Create config directory if it doesn't exist
mkdir -p /app/config
chown -R www-data:www-data /app/config

# Install Composer dependencies if vendor directory is empty or autoload.php is missing
if [ ! -f "/app/vendor/autoload.php" ]; then
    echo "📦 Installing Composer dependencies..."
    cd /app
    composer install --no-interaction --optimize-autoloader
    echo "✅ Dependencies installed successfully"
fi

# Set proper permissions for application
chown -R www-data:www-data /app

echo "Starting BiConnector Extension Application..."
echo "Environment: ${APP_ENV}"
echo "Log Level: ${LOG_LEVEL}"
echo "Log Path: ${LOG_PATH}"

# Start FrankenPHP
exec frankenphp run --config /etc/caddy/Caddyfile
