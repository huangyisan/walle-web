FROM php:8.2-fpm-bookworm

RUN apt-get update && apt-get install -y nginx gettext-base libicu-dev \
      zlib1g-dev unzip git subversion openssh-client ansible \
      && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install bcmath intl mbstring mysqli opcache pdo_mysql \
    && usermod -d /var/www www-data \
    && mkdir -p /data/walle-deploy /tmp/walle /var/log/nginx \
    && chown -R www-data:www-data /data/walle-deploy /tmp/walle

COPY ./ /opt/walle-web
COPY docker/php.ini /usr/local/etc/php/conf.d/walle-web.ini
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/fpm/zz-walle.conf /usr/local/etc/php-fpm.d/zz-walle.conf
COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/docker-start.sh /usr/local/bin/docker-start.sh

RUN rm -f /etc/nginx/sites-enabled/default

WORKDIR /opt/walle-web
RUN curl -sS https://getcomposer.org/installer | php \
      && mv composer.phar /usr/local/bin/composer \
      && chmod +x /usr/local/bin/composer
RUN composer install --prefer-dist --no-dev --optimize-autoloader
RUN chmod +x /entrypoint.sh /usr/local/bin/docker-start.sh \
    && chown -R www-data:www-data /opt/walle-web

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/local/bin/docker-start.sh"]
