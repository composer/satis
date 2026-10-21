ARG AWS_ACCOUNT_ID
ARG AWS_REGION
ARG ECR_REPOSITORY=docker-images
ARG FPM_IMAGE_TAG=php8.5-fpm-alpine

FROM ${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/${ECR_REPOSITORY}:${FPM_IMAGE_TAG}

# Copy sources
COPY . /var/www/satis/

RUN cd /var/www/satis/ \
# Move scripts
    && mv .docker/fpm/satisUpdater.sh /usr/local/bin/ \
    && mv .docker/fpm/supervisor/supervisord.ini /etc/supervisor.d/ \
    && chmod +x /usr/local/bin/satisUpdater.sh \
    && rm -Rf .docker \
# Install composer dependencies
    && composer install --no-dev --optimize-autoloader --no-progress --no-suggest --no-interaction \
# Build satis file
    && php /var/www/satis/bin/satis build /var/www/satis/satis.json /var/www/public/ -vvv

WORKDIR /var/www/satis
VOLUME "/var/www/public"

CMD "/usr/bin/supervisord"
