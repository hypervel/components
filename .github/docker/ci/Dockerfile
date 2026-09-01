ARG PHP_VERSION=8.4
ARG SWOOLE_VERSION=6.2.2

FROM phpswoole/swoole:${SWOOLE_VERSION}-php${PHP_VERSION}

ARG PHP_VERSION
ARG SWOOLE_VERSION
ARG PIE_VERSION=1.4.9
ARG PIE_CHECKSUM=19a31ddd4bfd08b9eb5eaad2e5f63e76e7919cae7683852da41c80da704ad6c0
ARG PROTOBUF_VERSION=5.36.1

LABEL org.opencontainers.image.source="https://github.com/hypervel/components"
LABEL org.opencontainers.image.description="PHP ${PHP_VERSION} and Swoole ${SWOOLE_VERSION} test image for Hypervel components"

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN set -eux; \
    apt-get update; \
    apt-get install --yes --no-install-recommends \
        curl \
        fswatch \
        git \
        libavif-dev \
        libfreetype6-dev \
        libgmp-dev \
        libheif-plugin-aomenc \
        libheif-plugin-x265 \
        libicu-dev \
        libjpeg-dev \
        libtool \
        libmagickwand-dev \
        libpng-dev \
        libpq-dev \
        libsqlite3-dev \
        libwebp-dev \
        procps \
        unzip; \
    rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    bundled_extensions=(pcntl intl gmp pdo_pgsql pdo_mysql pdo_sqlite sqlite3 bcmath fileinfo); \
    bundled_extensions_to_install=(); \
    for extension in "${bundled_extensions[@]}"; do \
        if ! php -r "exit(extension_loaded('${extension}') ? 0 : 1);"; then \
            bundled_extensions_to_install+=("${extension}"); \
        fi; \
    done; \
    if ! php -r 'exit(extension_loaded("gd") && function_exists("imagewebp") && function_exists("imageavif") ? 0 : 1);'; then \
        bundled_extensions_to_install+=(gd); \
        docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif; \
    fi; \
    if (( ${#bundled_extensions_to_install[@]} > 0 )); then \
        docker-php-ext-install -j"$(nproc)" "${bundled_extensions_to_install[@]}"; \
    fi

# PECL refuses to replace an older installed release unless forced.
RUN set -eux; \
    if ! php -r "exit(extension_loaded('protobuf') && version_compare((string) phpversion('protobuf'), '${PROTOBUF_VERSION}', '>=') ? 0 : 1);"; then \
        pecl install --force "protobuf-${PROTOBUF_VERSION}"; \
        docker-php-ext-enable protobuf; \
    fi

RUN set -eux; \
    pie_packages=(); \
    if ! php -r 'exit(extension_loaded("redis") && version_compare((string) phpversion("redis"), "6.3.0", ">=") ? 0 : 1);'; then \
        pie_packages+=(phpredis/phpredis); \
    fi; \
    if ! php -r 'exit(extension_loaded("imagick") ? 0 : 1);'; then \
        pie_packages+=(imagick/imagick); \
    fi; \
    if (( ${#pie_packages[@]} > 0 )); then \
        curl \
            --connect-timeout 15 \
            --fail \
            --location \
            --retry 5 \
            --retry-all-errors \
            --retry-max-time 180 \
            --show-error \
            "https://github.com/php/pie/releases/download/${PIE_VERSION}/pie.phar" \
            --output /tmp/pie.phar; \
        printf '%s  %s\n' "${PIE_CHECKSUM}" /tmp/pie.phar | sha256sum --check; \
        for pie_package in "${pie_packages[@]}"; do \
            php /tmp/pie.phar install --no-interaction "${pie_package}"; \
        done; \
        rm /tmp/pie.phar; \
    fi

RUN printf '%s\n' 'swoole.use_shortname=Off' > /usr/local/etc/php/conf.d/99-swoole-shortname.ini

RUN set -eux; \
    test "$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')" = "${PHP_VERSION}"; \
    test "$(php -r 'echo phpversion("swoole");')" = "${SWOOLE_VERSION}"; \
    php -r '$extensions = ["bcmath", "ctype", "curl", "dom", "fileinfo", "filter", "gd", "gmp", "imagick", "intl", "mbstring", "openssl", "pcntl", "pdo", "pdo_mysql", "pdo_pgsql", "pdo_sqlite", "posix", "protobuf", "redis", "session", "sockets", "sqlite3", "swoole", "tokenizer", "zlib"]; foreach ($extensions as $extension) { if (! extension_loaded($extension)) { fwrite(STDERR, "Missing required extension: {$extension}\n"); exit(1); } } foreach (["imagewebp", "imageavif"] as $function) { if (! function_exists($function)) { fwrite(STDERR, "Missing required GD function: {$function}()\n"); exit(1); } } if (version_compare((string) phpversion("redis"), "6.3.0", "<")) { fwrite(STDERR, "Redis extension 6.3.0 or newer is required for full integration coverage.\n"); exit(1); } if ((bool) ini_get("swoole.use_shortname")) { fwrite(STDERR, "Swoole short names must be disabled.\n"); exit(1); }'; \
    php -r "if (version_compare((string) phpversion('protobuf'), '${PROTOBUF_VERSION}', '<')) { fwrite(STDERR, 'protobuf extension ${PROTOBUF_VERSION} or newer is required for native OTLP coverage.' . PHP_EOL); exit(1); }"; \
    php -r 'foreach (["avif", "heic"] as $format) { $image = new Imagick; try { $image->newImage(1, 1, "white"); $image->setImageFormat($format); if ($image->getImageBlob() === "") { fwrite(STDERR, "Imagick produced an empty {$format} image.\n"); exit(1); } } finally { $image->clear(); $image->destroy(); } }'; \
    composer --version --no-ansi; \
    fswatch --version; \
    git --version; \
    ps --version
