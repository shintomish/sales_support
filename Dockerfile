FROM php:8.2-fpm

# システム依存 + Browsershot (Chromium + Node.js + 日本語フォント)
# nodesource の setup スクリプトは Debian 13 (trixie) に未対応のため、
# Debian 公式の nodejs/npm パッケージを使用 (trixie に Node 20 が含まれる)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    cron \
    gnupg \
    ca-certificates \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    chromium \
    fonts-noto-cjk \
    fonts-noto-cjk-extra \
    nodejs \
    npm \
    && docker-php-ext-install \
        zip \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY . .
# composer post-install (package:discover) は config を解決するため、
# ビルド時に .env が無い場合は .env.example をフォールバックとして使う。
# 実際の値はランタイムに env_file から読み込まれるため問題なし。
RUN if [ ! -f .env ]; then cp .env.example .env; fi \
    && composer install --no-interaction --prefer-dist --optimize-autoloader
# Browsershot 用 puppeteer (Chromium 本体は OS パッケージを使うのでダウンロードスキップ)
RUN PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true npm install puppeteer
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

# Laravel スケジューラーを毎分実行するcron設定
RUN echo "* * * * * www-data /usr/local/bin/php /var/www/artisan schedule:run >> /var/www/storage/logs/schedule.log 2>&1" > /etc/cron.d/laravel-scheduler \
    && chmod 0644 /etc/cron.d/laravel-scheduler

# Browsershot/Puppeteer に OS パッケージの Chromium を使わせる
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true

EXPOSE 9000
CMD ["sh", "-c", "mkdir -p /tmp/chromium-data && chmod 777 /tmp/chromium-data && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache && service cron start && php-fpm"]
