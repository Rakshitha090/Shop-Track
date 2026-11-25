# ShopTrack - Railway Deployment
FROM php:8.3-apache

# Install PostgreSQL dependencies and PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    && docker-php-ext-install pdo_pgsql pdo_mysql \
    && for m in mpm_event mpm_worker mpm_prefork; do \
        a2dismod "$m" 2>/dev/null || true; \
    done \
    && a2enmod mpm_prefork rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Railway port
ENV PORT=8080

WORKDIR /var/www/html

# Copy project files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 8080

# Start Apache on Railway's assigned port
CMD ["bash", "-c", "sed -i 's/^Listen 80$/Listen '${PORT}'/' /etc/apache2/ports.conf && sed -i 's#<VirtualHost \\*:80>#<VirtualHost *:'${PORT}'>#' /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]