FROM composer:1 AS build

WORKDIR /satis

COPY . /satis/

RUN set -eux ; \
  composer install \
    --no-interaction \
    --no-ansi \
    --no-scripts \
    --no-plugins \
    --no-dev \
    --prefer-dist \
    --no-progress \
    --no-suggest \
    --classmap-authoritative

FROM php:7-cli-alpine

RUN set -eux ; \
  apk add --no-cache --upgrade \
    bash \
    curl \
    git \
    libzip-dev \
    mercurial \
    openssh \
    openssl \
    subversion \
    unzip \
    zip ; \
  docker-php-ext-install zip

ENV COMPOSER_HOME /composer

COPY php-cli.ini /usr/local/etc/php/
COPY --from=build /satis /satis/

WORKDIR /build

ENTRYPOINT ["/satis/bin/docker-entrypoint.sh"]

CMD ["--ansi", "-vvv", "build", "/build/satis.json", "/build/output"]
