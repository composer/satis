FROM composer:1 AS build

WORKDIR /satis

COPY . /satis/

RUN set -eux; \
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

MAINTAINER https://github.com/composer/satis

RUN set -eux; \
  apk add --no-cache --upgrade \
    bash \
    curl \
    git \
    subversion \
    mercurial \
    openssh \
    openssl \
    zip \
    unzip

ENV COMPOSER_HOME /composer

COPY php-cli.ini /usr/local/etc/php/
COPY --from=build /satis /satis/

WORKDIR /build

ENTRYPOINT ["/satis/bin/docker-entrypoint.sh"]

CMD ["--ansi", "-vvv", "build", "/build/satis.json", "/build/output"]
