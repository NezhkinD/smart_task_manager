FROM php:8.4-fpm

WORKDIR /

RUN apt-get update && apt-get install -y \
    zip \
    vim \
    unzip \
    git \
    nano \
    wget \
    curl \
    libicu-dev \
    && pecl install xdebug-3.4.2 \
    && docker-php-ext-enable xdebug \
    && docker-php-ext-configure intl \
    && docker-php-ext-install intl

RUN curl -sSLf \
        -o /usr/local/bin/install-php-extensions \
        https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions && \
    chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions pdo_pgsql redis mongodb intl

COPY ./.docker/configs/php.ini /usr/local/etc/php/php.ini

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

ENV TZ=Europe/Moscow
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN echo "date.timezone = Europe/Moscow" > /usr/local/etc/php/conf.d/timezone.ini

WORKDIR /home/app

RUN chown -R www-data:www-data /var/cache
RUN chmod -R 775 /var/cache

RUN chown -R root:root /var/cache
RUN chmod -R 775 /var/cache

EXPOSE 9000
CMD ["php-fpm"]