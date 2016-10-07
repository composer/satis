FROM php:7-alpine

RUN apk --no-cache add curl git subversion openssh openssl mercurial tini

RUN echo "memory_limit=-1" > $PHP_INI_DIR/conf.d/memory-limit.ini \
    && echo "date.timezone=${PHP_TIMEZONE:-UTC}" > $PHP_INI_DIR/conf.d/date_timezone.ini

ENV COMPOSER_HOME /composer
ENV PATH /composer/vendor/bin:$PATH
ENV COMPOSER_ALLOW_SUPERUSER 1

RUN curl --silent --output /tmp/composer-setup.php https://getcomposer.org/installer \
    && curl --silent --output /tmp/composer-setup.sig https://composer.github.io/installer.sig \
    && php -r " \
        \$hash = hash('SHA384', file_get_contents('/tmp/composer-setup.php')); \
        \$signature = trim(file_get_contents('/tmp/composer-setup.sig')); \
        if (!hash_equals(\$signature, \$hash)) { \
            unlink('/tmp/composer-setup.php'); \
            echo 'Integrity check failed, installer is either corrupt or worse.' . PHP_EOL; \
            exit(1); \
        }"

RUN php /tmp/composer-setup.php --quiet --ansi --install-dir=/usr/bin --filename=composer \
    && rm /tmp/composer-setup.php \
    && composer --ansi --version

WORKDIR /build
WORKDIR /satis

RUN composer --ansi --quiet create-project composer/satis:dev-master .

VOLUME ["/composer", "/build"]

CMD ["--ansi", "-vvv", "build", "/build/satis.json", "/build/output"]

ENTRYPOINT ["/sbin/tini", "/satis/bin/satis"]
