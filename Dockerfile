FROM php:8.2-apache

# Install required system dependencies & PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) mysqli pdo pdo_mysql gd zip \
    && a2enmod rewrite headers \
    && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

# Set Apache DocumentRoot to the application directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html

# Configure all upload directories and permissions
RUN mkdir -p /var/www/html/app/uploads/achievements \
    && mkdir -p /var/www/html/app/uploads/applications \
    && mkdir -p /var/www/html/app/uploads/avatars \
    && mkdir -p /var/www/html/app/uploads/signatures \
    && mkdir -p /var/www/html/app/uploads/stamps \
    && chown -R www-data:www-data /var/www/html/app/uploads \
    && chmod -R 775 /var/www/html/app/uploads

EXPOSE 80

CMD ["apache2-foreground"]
