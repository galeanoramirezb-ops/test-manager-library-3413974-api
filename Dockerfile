FROM php:8.4-cli

# Instalar dependencias del sistema y extensiones de PHP requeridas
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo pdo_pgsql pgsql zip gd bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar archivos del proyecto
COPY . .

# Puerto expuesto para el servicio API
EXPOSE 8000

# Arranque del servidor de desarrollo de Laravel con migraciones automáticas
CMD ["sh", "-c", "mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/covers && chmod -R 777 storage bootstrap/cache && php artisan migrate --force --no-interaction && php artisan app:init-admin && php artisan serve --host=0.0.0.0 --port=8000"]

