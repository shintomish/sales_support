# 営業支援システム

Laravel ベースの営業支援システムです。

## 環境構築

### 必要なもの
- Docker Desktop
- Git
- Composer（Dockerコンテナ内で使用）

### セットアップ手順

1. **リポジトリをクローン**
```bash
git clone https://github.com/shintomish/sales_support.git
cd sales_support
```

2. **developブランチに切り替え**
```bash
git checkout develop
```

3. **`.env`ファイルを作成**
```bash
cp .env.example .env
```

`.env`の設定を確認（特にDB設定）:
```env
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=sales_support
DB_USERNAME=laravel
DB_PASSWORD=password
```

4. **Dockerコンテナ起動**
```bash
docker compose up -d
```

5. **依存パッケージのインストール**
```bash
docker compose exec app composer install
```

6. **アプリケーションキー生成**
```bash
docker compose exec app php artisan key:generate
```

7. **マイグレーション実行**
```bash
docker compose exec app php artisan migrate
```

8. **シーダー実行（テストデータ）**
```bash
docker compose exec app php artisan db:seed
```

9. **ブラウザでアクセス**
```
http://localhost:8000
```

## データベース接続

### DBeaver接続設定
```
Server Host: 127.0.0.1
Port: 3307
Database: sales_support
Username: laravel
Password: password
```

ドライバープロパティ:
- `allowPublicKeyRetrieval`: true
- `useSSL`: false

## Git運用ルール

### ブランチ戦略
- `main`: 本番環境用（直接プッシュ禁止）
- `develop`: 開発統合ブランチ
- `feature/*`: 機能開発用ブランチ

### 作業フロー

#### 1. 新機能開発を開始
```bash
# 最新のdevelopを取得
git checkout develop
git pull origin develop

# 機能ブランチを作成
git checkout -b feature/機能名
```

#### 2. 開発作業
```bash
# 変更をステージング
git add .

# コミット（日本語でOK）
git commit -m "顧客一覧画面を実装"
```

#### 3. developにマージ
```bash
# developブランチに切り替え
git checkout develop

# 最新版を取得
git pull origin develop

# 機能ブランチをマージ
git merge feature/機能名

# GitHubにプッシュ
git push origin develop

# 機能ブランチを削除
git branch -d feature/機能名
```

### コミットメッセージの例
- `顧客一覧機能を実装`
- `顧客登録フォームにバリデーション追加`
- `商談ステータス更新APIを修正`
- `README更新：環境構築手順を追記`

## プロジェクト構成

### データベース設計

#### customers（顧客）
- 会社名、業種、従業員数、住所、電話番号など

#### contacts（担当者）
- 顧客に紐づく担当者情報

#### deals（商談）
- 商談名、金額、ステータス、成約確度など

#### activities（活動履歴）
- 訪問、電話、メールなどの営業活動記録

#### tasks（タスク）
- 営業担当者のタスク管理

## 開発環境

- Laravel 11.x
- PHP 8.2
- MySQL 8.0
- Nginx (Alpine)
- Docker Compose

## トラブルシューティング

### コンテナが起動しない
```bash
docker compose down
docker compose up -d --build
```

### データベース接続エラー
```bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan migrate:fresh --seed
```

### ポート競合
`docker-compose.yml`の`ports`設定を変更してください。

## ライセンス

プライベートプロジェクト
