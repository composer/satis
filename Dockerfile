FROM composer:1 AS build

WORKDIR /satis

COPY . /satis/

RUN composer install \
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

RUN apk --no-cache add bash curl git subversion mercurial openssh openssl tini zip unzip \
 && apk add --update --no-cache --virtual .build-deps zlib-dev libzip-dev \
 && docker-php-ext-configure zip --with-libzip \
 && docker-php-ext-install -j$(getconf _NPROCESSORS_ONLN) zip \
 && runDeps="$( \
    scanelf --needed --nobanner --format '%n#p' --recursive /usr/local/lib/php/extensions \
    | tr ',' '\n' \
    | sort -u \
    | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }' \
    )" \
 && apk add --virtual .phpext-rundeps $runDeps \
 && apk del .build-deps

ENV COMPOSER_HOME /composer

COPY php-cli.ini /usr/local/etc/php/
COPY --from=build /satis /satis/

WORKDIR /satis

ENTRYPOINT ["/sbin/tini", "-g", "--", "/satis/bin/docker-entrypoint.sh"]

CMD ["--ansi", "-vvv", "build", "/build/satis.json", "/build/output"]
