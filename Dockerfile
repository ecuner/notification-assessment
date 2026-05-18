ARG PHP_VERSION=8.5

###########################################
# Composer dependencies stage
###########################################
FROM php:${PHP_VERSION}-cli-alpine AS composer-deps

WORKDIR /app

# Install php-extension-installer once — reused in main stage via COPY --from
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl sockets zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy composer files
COPY composer.json composer.lock ./

# Install composer dependencies (no autoloader yet, will optimize in final stage)
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-autoloader \
    --no-ansi \
    --no-scripts \
    --prefer-dist

###########################################
# Main application stage
###########################################
FROM php:${PHP_VERSION}-fpm-alpine

ARG WWWUSER=1000
ARG WWWGROUP=1000
ARG TZ=UTC

ENV TERM=xterm-color \
    WITH_HORIZON=false \
    WITH_SCHEDULER=false \
    USER=eray \
    ROOT=/var/www/html \
    COMPOSER_FUND=0 \
    COMPOSER_MAX_PARALLEL_HTTP=24

WORKDIR ${ROOT}

SHELL ["/bin/sh", "-eou", "pipefail", "-c"]

RUN ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime \
  && echo ${TZ} > /etc/timezone

# Reuse install-php-extensions and composer from composer-deps stage (no re-download)
COPY --from=composer-deps /usr/local/bin/install-php-extensions /usr/local/bin/install-php-extensions
COPY --from=composer-deps /usr/bin/composer /usr/bin/composer

# Install system dependencies and PHP extensions in one layer
RUN apk update && \
    apk upgrade && \
    apk add --no-cache \
    curl \
    wget \
    nano \
    ncdu \
    procps \
    ca-certificates \
    supervisor \
    nginx \
    libcap \
    libsodium-dev && \
    setcap 'cap_net_bind_service=+ep' /usr/sbin/nginx && \
    install-php-extensions \
    bz2 \
    pcntl \
    mbstring \
    bcmath \
    sockets \
    pgsql \
    pdo_pgsql \
    opcache \
    exif \
    pdo_mysql \
    zip \
    intl \
    gd \
    redis \
    igbinary && \
    docker-php-source delete && \
    rm -rf /var/cache/apk/* /tmp/* /var/tmp/*

RUN arch="$(apk --print-arch)" \
    && case "$arch" in \
    armhf)   _cronic_fname='supercronic-linux-arm' ;; \
    aarch64) _cronic_fname='supercronic-linux-arm64' ;; \
    x86_64)  _cronic_fname='supercronic-linux-amd64' ;; \
    x86)     _cronic_fname='supercronic-linux-386' ;; \
    *) echo >&2 "error: unsupported architecture: $arch"; exit 1 ;; \
    esac \
    && wget -q "https://github.com/aptible/supercronic/releases/download/v0.2.29/${_cronic_fname}" \
    -O /usr/bin/supercronic \
    && chmod +x /usr/bin/supercronic \
    && mkdir -p /etc/supercronic \
    && echo "*/1 * * * * php ${ROOT}/artisan schedule:run --no-interaction" > /etc/supercronic/laravel

RUN addgroup -g ${WWWGROUP} ${USER} \
    && adduser -D -h ${ROOT} -G ${USER} -u ${WWWUSER} -s /bin/sh ${USER}

RUN mkdir -p /var/log/supervisor /var/run/supervisor /run /var/lib/nginx/logs /var/log/nginx /tmp/opcache \
    && chown -R ${USER}:${USER} ${ROOT} /var/log /var/run /run /var/lib/nginx /tmp/opcache \
    && chmod -R a+rw ${ROOT} /var/log /var/run /run /var/lib/nginx

# Install system config files (as root, before USER switch)
COPY .docker/php.ini ${PHP_INI_DIR}/php.ini
COPY .docker/nginx.conf /etc/nginx/nginx.conf
COPY .docker/conf.d/default.conf /etc/nginx/conf.d/default.conf
COPY .docker/fpm-pool.conf /usr/local/etc/php-fpm.d/www.conf
COPY .docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY .docker/supervisord.fpm.conf /etc/supervisor/conf.d/supervisord.fpm.conf
COPY .docker/supervisord.horizon.conf /etc/supervisor/conf.d/supervisord.horizon.conf
COPY .docker/supervisord.scheduler.conf /etc/supervisor/conf.d/supervisord.scheduler.conf
COPY .docker/supervisord.worker.conf /etc/supervisor/conf.d/supervisord.worker.conf
COPY .docker/start-container /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container

USER ${USER}

# Copy vendor from composer-deps stage for better caching
COPY --chown=${USER}:${USER} --from=composer-deps /app/vendor ./vendor

# Copy composer files (needed for autoloader generation)
COPY --chown=${USER}:${USER} composer.json composer.lock ./

# Copy application code
COPY --chown=${USER}:${USER} . .

# Copy environment file
COPY --chown=${USER}:${USER} .env.example ./.env

# Create runtime directories, generate autoloader, discover packages
RUN mkdir -p \
    bootstrap/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache/data \
    storage/framework/testing \
    storage/logs && \
    rm -f bootstrap/cache/packages.php bootstrap/cache/services.php && \
    composer dump-autoload --optimize --no-dev --no-scripts && \
    php artisan package:discover --ansi && \
    composer clear-cache && \
    chmod -R a+rw storage && \
    cat .docker/utilities.sh >> ~/.bashrc && \
    rm -rf .docker

EXPOSE 80

ENTRYPOINT ["start-container"]

HEALTHCHECK --start-period=10s --interval=10s --timeout=5s --retries=6 CMD wget -qO- http://localhost/api/v1/health || exit 1
