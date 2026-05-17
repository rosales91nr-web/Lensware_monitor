FROM php:8.2-fpm-alpine

# Install nginx, supervisor, and PHP extensions
RUN apk add --no-cache nginx supervisor \
    && docker-php-ext-install opcache

# Create required directories
RUN mkdir -p /var/www/html/cache \
             /var/www/html/backups \
             /var/www/html/logs \
             /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html

# Copy nginx config
COPY docker/nginx.conf /etc/nginx/nginx.conf

# Copy supervisor config
COPY docker/supervisord.conf /etc/supervisord.conf

# Copy application files
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/cache \
                    /var/www/html/backups \
                    /var/www/html/logs \
                    /var/www/html/uploads

EXPOSE 8080

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
