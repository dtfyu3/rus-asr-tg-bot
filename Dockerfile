FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    ffmpeg \
    zip \
    unzip \
    curl \
    && docker-php-ext-install zip

# Копируем проект внутрь контейнера
COPY . /var/www/html

# Разрешаем .htaccess (если понадобится)
RUN a2enmod rewrite

# Убедимся, что все права на файлы настроены корректно
RUN chown -R www-data:www-data /var/www/html
