FROM php:8.2-apache

# Install required system dependencies & PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    zip \
    unzip \
    curl \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) mysqli pdo pdo_mysql gd zip bcmath intl opcache \
    && a2enmod rewrite headers \
    && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html

# Configure storage, cache, and upload directories
RUN mkdir -p /var/www/html/storage/framework/sessions \
    && mkdir -p /var/www/html/storage/framework/views \
    && mkdir -p /var/www/html/storage/framework/cache \
    && mkdir -p /var/www/html/storage/logs \
    && mkdir -p /var/www/html/storage/backups \
    && mkdir -p /var/www/html/bootstrap/cache \
    && mkdir -p /var/www/html/app/uploads/achievements \
    && mkdir -p /var/www/html/app/uploads/applications \
    && mkdir -p /var/www/html/app/uploads/avatars \
    && mkdir -p /var/www/html/app/uploads/signatures \
    && mkdir -p /var/www/html/app/uploads/stamps \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/app/uploads \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/app/uploads

EXPOSE 80

HEALTHCHECK --interval=20s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -f -s http://127.0.0.1:80/health.php || curl -f -s http://127.0.0.1:80/ || exit 0

CMD ["apache2-foreground"]
