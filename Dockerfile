FROM php:8.2-apache

# Install dependencies untuk GD dan Imagick
RUN apt-get update && apt-get install -y \
    libfreetype-dev \
    libjpeg-dev \
    libpng-dev \
    libmagickwand-dev --no-install-recommends \
    pkg-config \
    libssl-dev \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd mysqli pdo pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite (kalau perlu)
RUN a2enmod rewrite

# Copy source ke container
COPY . /var/www/html/

# Set permission
RUN chown -R www-data:www-data /var/www/html
