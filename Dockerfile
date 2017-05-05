FROM php:7-alpine

MAINTAINER https://github.com/composer/satis

RUN apk --no-cache add curl git subversion mercurial openssh openssl tini zlib-dev

RUN docker-php-ext-install zip \
 && echo "memory_limit=-1" > "$PHP_INI_DIR/conf.d/memory-limit.ini" \
 && echo "date.timezone=${PHP_TIMEZONE:-UTC}" > "$PHP_INI_DIR/conf.d/date_timezone.ini"

ENV COMPOSER_HOME /composer
ENV PATH "/composer/vendor/bin:$PATH"
ENV COMPOSER_ALLOW_SUPERUSER 1

RUN mkdir /build \
 && mkdir /composer \
 && chmod -R 777 /composer \
 && curl -sfLo /tmp/composer-setup.php https://getcomposer.org/installer \
 && curl -sfLo /tmp/composer-setup.sig https://composer.github.io/installer.sig \
 && php -r " \
    \$hash = hash('SHA384', file_get_contents('/tmp/composer-setup.php')); \
    \$signature = trim(file_get_contents('/tmp/composer-setup.sig')); \
    if (!hash_equals(\$signature, \$hash)) { \
        unlink('/tmp/composer-setup.php'); \
        echo 'Integrity check failed, installer is either corrupt or worse.' . PHP_EOL; \
        exit(1); \
    }" \
 && php /tmp/composer-setup.php --no-ansi --install-dir=/usr/bin --filename=composer \
 && composer --no-interaction --no-ansi --version \
 && rm /tmp/composer-setup.php

WORKDIR /satis

COPY ["composer.json", "composer.lock", "/satis/"]

RUN composer install --no-interaction --no-ansi --no-autoloader --no-scripts --no-plugins --no-dev

COPY bin /satis/bin/
COPY res /satis/res/
COPY views /satis/views/
COPY src /satis/src/

RUN composer dump-autoload --no-interaction --no-ansi --optimize --no-dev

ENTRYPOINT ["/sbin/tini", "-g", "--", "/satis/bin/docker-entrypoint.sh"]

CMD ["--ansi", "-vvv", "build", "/build/satis.json", "/build/output"]
