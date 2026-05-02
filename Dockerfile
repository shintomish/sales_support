# Trixie の xz が landlock sandbox を使うが、本番 Docker daemon の
# 古い seccomp プロファイルでブロックされて apt 展開が失敗するため、
# 安定版の Bookworm (Debian 12) を明示的に使用
FROM php:8.2-fpm-bookworm

# システム依存 + Browsershot 用 Node + 日本語フォント + Chromium ランタイム依存
# Chromium 本体は apt ではなく puppeteer 内蔵版を使用 (バージョン固定のため)
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
    fonts-noto-cjk \
    nodejs \
    npm \
    libnss3 \
    libatk1.0-0 \
    libatk-bridge2.0-0 \
    libcups2 \
    libdrm2 \
    libxkbcommon0 \
    libxcomposite1 \
    libxdamage1 \
    libxfixes3 \
    libxrandr2 \
    libgbm1 \
    libpango-1.0-0 \
    libcairo2 \
    libasound2 \
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
# Browsershot 用 puppeteer (Chromium 自動ダウンロード)
# キャッシュは /var/www/.cache/puppeteer に置かれ、ランタイムで HOME=/tmp に切替えても
# Browsershot が PUPPETEER_CACHE_DIR を見るため動作する
ENV PUPPETEER_CACHE_DIR=/var/www/.cache/puppeteer
RUN npm install puppeteer && chmod -R 755 /var/www/.cache
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache /var/www/.cache

# Laravel スケジューラーを毎分実行するcron設定
RUN echo "* * * * * www-data /usr/local/bin/php /var/www/artisan schedule:run >> /var/www/storage/logs/schedule.log 2>&1" > /etc/cron.d/laravel-scheduler \
    && chmod 0644 /etc/cron.d/laravel-scheduler

# Browsershot は puppeteer がダウンロードした Chromium を自動検出して使う
# PUPPETEER_EXECUTABLE_PATH を未指定にしておくことで puppeteer のキャッシュを参照

EXPOSE 9000
CMD ["sh", "-c", "mkdir -p /tmp/chromium-data && chmod 777 /tmp/chromium-data && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache && service cron start && php-fpm"]
