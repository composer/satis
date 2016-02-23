FROM php:5.6

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y git zlib1g-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install zip \
    && echo "date.timezone = UTC" > /usr/local/etc/php/php.ini

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer \
    && composer create-project composer/satis --stability=dev --no-dev

WORKDIR /build
ENTRYPOINT ["/satis/bin/satis"]
