FROM php:8.2-fpm-alpine

# Variables Railway
ENV PORT=8080

# Install nginx, supervisor, and PHP extensions
RUN apk add --no-cache nginx supervisor \
    && docker-php-ext-install opcache

# Crear TODAS las carpetas necesarias
RUN mkdir -p /var/www/html/cache \
             /var/www/html/backups \
             /var/www/html/logs \
             /var/www/html/uploads \
             /var/www/html/data/reports \
             /var/www/html/data/staging \
             /var/www/html/data/backups \
             /tmp/lensware/staging \
             /tmp/lensware/backups \
             /tmp/lensware/cache \
             /tmp/lensware/logs \
             /run/nginx \
    && chown -R www-data:www-data /var/www/html /tmp/lensware \
    && chmod -R 777 /tmp/lensware

# Configuración nginx
COPY docker/nginx.conf /etc/nginx/nginx.conf

# Configuración supervisor
COPY docker/supervisord.conf /etc/supervisord.conf

# Copiar aplicación
COPY . /var/www/html/

# Permisos
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/cache \
                    /var/www/html/backups \
                    /var/www/html/logs \
                    /var/www/html/uploads \
                    /var/www/html/data

# Configurar PHP-FPM para escuchar en 9000 correctamente
RUN sed -i 's|listen = 127.0.0.1:9000|listen = 9000|g' /usr/local/etc/php-fpm.d/www.conf

# Healthcheck Railway
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
  CMD wget -qO- http://127.0.0.1:8080/health || exit 1

# Exponer puerto nginx
EXPOSE 8080

# Iniciar supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
