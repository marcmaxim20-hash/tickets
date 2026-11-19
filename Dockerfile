FROM php:8.3-apache

# Install system dependencies and PHP extensions required by Symfony & MySQL
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libicu-dev \
    libzip-dev \
    && docker-php-ext-install \
    pdo_mysql \
    intl \
    zip \
    opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure Apache DocumentRoot for Symfony (/public)
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint-app.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-app.sh

RUN printf "upload_max_filesize = 16M\npost_max_size = 20M\n" > /usr/local/etc/php/conf.d/uploads.ini

EXPOSE 80

ENTRYPOINT ["docker-entrypoint-app.sh"]
CMD ["apache2-foreground"]
