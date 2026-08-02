# S3 Lite — application image
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libssl-dev libcurl4-openssl-dev libssh2-1 \
        default-mysql-client cron \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure ftp --with-openssl-dir=/usr \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd zip opcache ftp \
    && pecl install redis && docker-php-ext-enable redis \
    && a2enmod rewrite headers \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Storage backends need these: ext-ftp drives FTP and FTPS, and SFTP rides on
# cURL's sftp:// protocol. Fail the build rather than ship an image where the
# Storage page quietly reports half its drivers as unavailable.
RUN php -r 'exit(extension_loaded("ftp") ? 0 : 1);' \
        || (echo "ext-ftp missing — FTP and FTPS backends would not work" && exit 1) \
    && php -r 'exit(function_exists("ftp_ssl_connect") ? 0 : 1);' \
        || (echo "ftp_ssl_connect missing — FTPS backends would not work" && exit 1) \
    && php -r 'exit(in_array("sftp", curl_version()["protocols"], true) ? 0 : 1);' \
        || echo "note: this cURL has no SFTP support; FTP, FTPS and S3 backends still work"

# Uploads need generous limits; the app enforces its own maximum on top.
RUN { \
        echo 'upload_max_filesize = 2048M'; \
        echo 'post_max_size = 2048M'; \
        echo 'memory_limit = 512M'; \
        echo 'max_execution_time = 600'; \
        echo 'max_input_time = 600'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.validate_timestamps = 0'; \
        echo 'expose_php = Off'; \
    } > /usr/local/etc/php/conf.d/s3lite.ini

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
       > /etc/apache2/conf-available/s3lite.conf \
    && a2enconf s3lite

WORKDIR /var/www/html
COPY . /var/www/html

RUN mkdir -p storage/files storage/tmp storage/chunks storage/logs storage/cache storage/backups storage/sftp \
    && chown -R www-data:www-data storage \
    && chmod -R 775 storage

# Maintenance jobs (the container also works fine without cron).
RUN printf '* * * * * www-data php /var/www/html/bin/console queue:run-once >/dev/null 2>&1\n\
*/5 * * * * www-data php /var/www/html/bin/console job:run metrics.sample >/dev/null 2>&1\n\
0 3 * * * www-data php /var/www/html/bin/console job:run cleanup >/dev/null 2>&1\n' \
    > /etc/cron.d/s3lite && chmod 0644 /etc/cron.d/s3lite

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/api/v1/ping") ? 0 : 1);'

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
