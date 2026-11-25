FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    && docker-php-ext-install pdo_pgsql pdo_mysql \
    && for m in mpm_event mpm_worker mpm_prefork; do \
        a2dismod "$m" 2>/dev/null || true; \
    done \
    && rm -f /etc/apache2/mods-enabled/mpm_*.load \
    && a2enmod mpm_prefork rewrite headers \
    && apache2ctl -M \
    && rm -rf /var/lib/apt/lists/*

ENV PORT=8080

WORKDIR /var/www/html

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 8080

CMD ["apache2-foreground"]