FROM php:8.3-fpm-bookworm
RUN docker-php-ext-install mysqli pdo_mysql pcntl \
    && printf 'expose_php=Off\ndisplay_errors=Off\nlog_errors=On\nsession.save_path=/tmp\nupload_tmp_dir=/tmp\n' > /usr/local/etc/php/conf.d/dualscan.ini
COPY deployment/runtime-entrypoint.php /opt/dualscan/runtime-entrypoint.php
WORKDIR /var/www/html
ENTRYPOINT ["php", "/opt/dualscan/runtime-entrypoint.php"]
