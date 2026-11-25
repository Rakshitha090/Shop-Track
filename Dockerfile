FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    && docker-php-ext-install pdo_pgsql pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Remove every enabled MPM module
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load \
          /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork rewrite headers

ENV PORT=8080

WORKDIR /var/www/html

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 8080

CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT}/\" /etc/apache2/ports.conf && sed -i \"s#\\*:80#*:${PORT}#\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]