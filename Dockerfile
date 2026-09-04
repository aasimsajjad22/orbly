# syntax=docker/dockerfile:1.7

# ============================================================
# Stage 1: base — the PHP runtime, shared by every later stage
# ============================================================
# Alpine for size. The trade-off is musl libc instead of glibc, which
# occasionally breaks exotic extensions — none of ours.
FROM php:8.4-fpm-alpine AS base

# System packages needed to BUILD extensions. Split from the runtime
# packages below so we can delete these afterwards.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        rabbitmq-c-dev \
        icu-dev \
        libzip-dev \
    # Runtime libraries — these must STAY.
    && apk add --no-cache \
        postgresql-libs \
        rabbitmq-c \
        icu-libs \
        libzip \
        fcgi \
    # pdo_pgsql: Doctrine's database driver
    # intl:      needed by Symfony's validator and translator
    # zip:       Composer uses it for package extraction
    # opcache:   compiles PHP to bytecode once instead of per request
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        intl \
        zip \
        opcache \
    # amqp is a PECL extension, not bundled with PHP. This is the same
    # extension that fought you on macOS in Phase 5 — inside a Linux
    # container it is three lines and always works.
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    # Delete the build toolchain. Keeping it would roughly double the
    # image size for no runtime benefit.
    && apk del .build-deps

# Composer, copied from its official image rather than installed via a
# script — reproducible, and pinned to a version.
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ============================================================
# Stage 2: vendor — install PHP dependencies
# ============================================================
# A separate stage so dependency installation is cached independently of
# application code. Change a controller and this stage is reused; change
# composer.json and only this stage rebuilds.
FROM base AS vendor

# ONLY the dependency manifests, not the whole app. This is the key to
# layer caching: Docker reuses this layer whenever these two files are
# unchanged, even if every other file in the project changed.
COPY composer.json composer.lock ./

# --no-dev:            skip PHPUnit, Foundry, the profiler
# --no-scripts:        Symfony's post-install scripts need the app code,
#                      which is not here yet — they run in the next stage
# --optimize-autoloader: build a static classmap instead of scanning at
#                      runtime; meaningfully faster in production
# --no-interaction:    fail rather than prompt in a non-interactive build
RUN --mount=type=cache,target=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer \
    composer install \
        --no-dev \
        --no-scripts \
        --optimize-autoloader \
        --no-interaction \
        --prefer-dist

# ============================================================
# Stage 3: assets — build the CSS
# ============================================================
# Tailwind needs the templates to scan, and produces a stylesheet the
# runtime image needs. Doing it in its own stage keeps the Tailwind
# binary out of the final image.
FROM base AS assets

# Without this, APP_ENV defaults to dev — and the dev bundles were
# removed by --no-dev, so booting the kernel fails.
# APP_SECRET is required for the container to build at all; the value is
# irrelevant here because nothing is signed during an asset build.
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_SECRET=build-time-only

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN php bin/console importmap:install

RUN php bin/console tailwind:build --minify \
    && php bin/console asset-map:compile

# ============================================================
# Stage 4: app — the runtime image
# ============================================================
FROM base AS app

ENV APP_ENV=prod \
    APP_DEBUG=0

# Production PHP settings. The php.ini-production file ships with the
# image but is not active by default.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/opcache.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"
COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

# Application code, then vendor, then the built assets. Ordered by how
# often each changes — code churns most, so it goes in the layer that
# gets invalidated most readily.
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/assets ./public/assets

# Now the app code is present, so the deferred Composer scripts can run.
# dump-env compiles .env into a single PHP file, removing the runtime
# cost of parsing dotenv files on every request.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && composer dump-env prod \
    # Warm the container cache at BUILD time. Without this the first
    # request after a deploy pays the cost of compiling the whole
    # container — several seconds, on the request of a real user.
    && php bin/console cache:warmup --env=prod \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

# Copy the entrypoint and make it executable. This MUST happen while we
# are still root — chmod as www-data on a root-owned file fails.
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
COPY docker/php/fpm-healthcheck.sh /usr/local/bin/fpm-healthcheck
RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/fpm-healthcheck

# Run as a non-root user from here on. A container escape as root is far
# worse than one as www-data, and some orchestrators refuse root
# containers outright.
USER www-data

EXPOSE 9000

# Kubernetes and ECS use this to decide when a container is ready for
# traffic, and when to restart a stuck one.
HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=3 \
    CMD ["fpm-healthcheck"]

# ENTRYPOINT and CMD combine: Docker runs "entrypoint php-fpm". The
# script's `exec "$@"` then replaces itself with php-fpm, so PHP becomes
# PID 1 and receives Docker's stop signals directly.
#
# Overriding CMD (as the worker stage does) still runs the entrypoint,
# so the worker gets the same database wait for free.
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php-fpm"]

# ============================================================
# Stage 5: worker — same image, different process
# ============================================================
# ONE artifact, two roles. Web and worker run identical code, so a deploy
# cannot leave workers executing yesterday's version against today's
# database — the exact problem you hit in Phase 8 with a stale worker.
FROM app AS worker

# --limit and --time-limit make the worker exit deliberately so the
# orchestrator restarts it with a fresh process. A PHP process running
# for days accumulates memory; dying on purpose is more reliable than
# trying to prevent that.
#
# The ENTRYPOINT from the app stage still runs, so the worker gets the
# same database-wait for free — only CMD changes.
CMD ["php", "bin/console", "messenger:consume", "async", \
     "--limit=100", "--time-limit=3600", "--memory-limit=128M", "-vv"]
