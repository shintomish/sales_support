# 営業支援システム

## 環境構築

### 必要なもの
- Docker Desktop
- Git

### セットアップ手順

1. リポジトリをクローン
```bash
git clone https://github.com/shintomish/sales_support.git
cd sales_support
```

2. `.env`ファイルを作成
```bash
cp .env.example .env
```

3. Dockerコンテナ起動
```bash
docker compose up -d
```

4. マイグレーション実行
```bash
docker compose exec app php artisan migrate
```

5. シーダー実行（テストデータ）
```bash
docker compose exec app php artisan db:seed
```

6. ブラウザでアクセス
```
http://localhost:8000
```

## Git運用ルール

### ブランチ戦略
- `main`: 本番環境
- `develop`: 開発環境
- `feature/*`: 機能開発

### 作業手順
1. developから機能ブランチを作成
2. 機能開発
3. developにマージ
