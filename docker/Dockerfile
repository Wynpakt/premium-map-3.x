# Local dev image: latest stable PHP 8.5 + Phalcon 5.23.
# PHP 8.6 and Phalcon 6 are still release candidates.
# Moving tag. Compose pulls it so a cached 8.5.11 layer is not reused.
# The entrypoint refuses anything older than 8.5.12.
FROM php:8.5-apache-bookworm

# deb.debian.org stalls from this network; use a mirror that completes.
RUN sed -i \
        -e 's|http://deb.debian.org/debian-security|https://deb.debian.org/debian-security|g' \
        -e 's|http://deb.debian.org/debian|https://mirrors.edge.kernel.org/debian|g' \
        /etc/apt/sources.list.d/debian.sources \
    && apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql \
    && pecl install phalcon-5.23.0 \
    && docker-php-ext-enable phalcon \
    && php -m | grep -qi phalcon \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && a2enmod rewrite \
    && rm -rf /tmp/pear /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2ctl", "-D", "FOREGROUND"]
