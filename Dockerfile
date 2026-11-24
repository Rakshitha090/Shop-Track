# ==============================================================================
# ShopTrack - Production Docker Container (PHP 8.3 + Apache + PostgreSQL Support)
# ==============================================================================
FROM php:8.3-apache

# Install PostgreSQL client libraries and required PHP extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Configure Apache port mapping for cloud container platforms (Render, Railway, Fly.io)
# Cloud platforms assign a dynamic PORT environment variable (default: 80)
ENV PORT=80
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Set working directory and copy application source code
WORKDIR /var/www/html
COPY . /var/www/html/

# Secure file permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
