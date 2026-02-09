FROM php:8.2-fpm

# 作業ディレクトリ設定
WORKDIR /var/www

# 必要なパッケージのインストール
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev

# PHP拡張機能のインストール
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Composerのインストール
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ユーザーを作成
RUN useradd -G www-data,root -u 1000 -d /home/shintomi shintomi
RUN mkdir -p /home/shintomi/.composer && \
    chown -R shintomi:shintomi /home/shintomi

# 権限設定
RUN chown -R shintomi:shintomi /var/www

USER shintomi

EXPOSE 9000
CMD ["php-fpm"]
