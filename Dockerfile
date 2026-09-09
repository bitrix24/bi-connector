FROM dunglas/frankenphp:php8.4

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libcurl4-openssl-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libicu-dev \
    libpq-dev \
    mariadb-client \
    postgresql-client \
    telnet \
    netcat-traditional \
    iputils-ping \
    && docker-php-ext-configure intl \
    && docker-php-ext-install intl bcmath curl pdo pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Memory boundary of a request.
#
# The base image loads no php.ini at all, so without this file a request gets the built-in 128M. On the
# MySQL and PostgreSQL paths the driver collects the whole result before the first row is read, so the
# memory a request needs is the row limit of the application multiplied by the width of a row: at the
# default MAX_RESULT_ROWS of 500000 a row of 300 bytes costs about 176 MB and a row of 900 bytes about
# 476 MB. The two settings are one pair -- raising MAX_RESULT_ROWS without raising this value turns the
# row limit back into a limit that memory reaches first.
RUN echo 'memory_limit = 512M' > "$PHP_INI_DIR/conf.d/zz-app.ini"

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy application code
COPY . .

# Copy Caddyfile to the correct location
COPY Caddyfile /etc/caddy/Caddyfile

# Copy and set permissions for database connectivity scripts
COPY scripts/ /app/scripts/
RUN chmod +x /app/scripts/*.sh

# Copy entrypoint script
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Create log directory
RUN mkdir -p /var/log && chmod 755 /var/log

# Set permissions
RUN chown -R www-data:www-data /app /var/log

# Expose port
EXPOSE 80 443

# Set entrypoint
ENTRYPOINT ["/entrypoint.sh"]

# Start FrankenPHP
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
