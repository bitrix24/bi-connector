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
