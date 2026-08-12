FROM composer:latest AS build

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

FROM php:8.4-cli-alpine

RUN set -eux ; \
  apk upgrade --no-cache ; \
  apk add --no-cache --upgrade \
    7zip \
    bash \
    coreutils \
    curl \
    git \
    mercurial \
    openssh-client \
    openssl \
    patch \
    subversion \
    tini \
    unzip \
    zip ; \
  # install https://github.com/mlocati/docker-php-extension-installer
  curl \
    --silent \
    --fail \
    --location \
    --retry 3 \
    --output /usr/local/bin/install-php-extensions \
    --url https://github.com/mlocati/docker-php-extension-installer/releases/download/2.11.12/install-php-extensions \
  ; \
  echo 0c3594c9865bf1e2372cfd3da355cf5115c56fdcc9956218e06c130d99d7754d806088d8d0771f6e84f01e93cd65928df2579d50d7d66811010552eae6fe671a /usr/local/bin/install-php-extensions | sha512sum --strict --check ; \
  install-php-extensions \
    bz2 \
    sockets \
    zip

ENV COMPOSER_HOME=/composer

COPY php-cli.ini /usr/local/etc/php/
COPY --from=build /satis /satis/

WORKDIR /build

ENTRYPOINT ["/sbin/tini", "--", "/satis/bin/docker-entrypoint.sh"]

CMD ["--ansi", "-vvv", "build", "/build/satis.json", "/build/output"]
