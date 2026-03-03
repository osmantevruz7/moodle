FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libgd-dev \
    libicu-dev \
    libzip-dev \
    libxml2-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libonig-dev \
    libssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required by Moodle 4.4
RUN docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        curl \
        gd \
        intl \
        mbstring \
        opcache \
        soap \
        simplexml \
        xml \
        zip \
        pdo_mysql \
        mysqli \
        exif

# PHP settings required/recommended by Moodle
RUN { \
    echo 'max_input_vars = 5000'; \
    echo 'memory_limit = 256M'; \
    echo 'post_max_size = 64M'; \
    echo 'upload_max_filesize = 64M'; \
    echo 'opcache.enable = 1'; \
    echo 'opcache.memory_consumption = 128'; \
} > /usr/local/etc/php/conf.d/moodle.ini

# Enable mod_rewrite and allow .htaccess overrides
RUN a2enmod rewrite \
    && sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Create moodledata directory outside the webroot with correct permissions
RUN mkdir -p /var/moodledata && chown -R www-data:www-data /var/moodledata
