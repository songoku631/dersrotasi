FROM php:8.3-apache

WORKDIR /var/www/html

RUN docker-php-ext-install pdo pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts

COPY . .

RUN composer dump-autoload --optimize \
  && a2enmod rewrite \
  && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

EXPOSE 80
