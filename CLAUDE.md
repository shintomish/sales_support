# 営業支援システム - Laravel API

## プロジェクト概要
SES企業向け営業支援システム。Laravel 11 (API) + Next.js 15 (フロント) + Supabase PostgreSQL構成。

## 環境構成
| 環境 | URL |
|------|-----|
| ローカル Laravel API | http://localhost:8090 |
| ローカル Next.js | http://localhost:3000 |
| 本番 API | https://sales.ai-mon.net |
| 本番 フロント | https://app.ai-mon.net |

## 技術スタック
- Laravel 11, PHP 8.2
- Supabase PostgreSQL（Session Pooler経由）
- Supabase Auth（ES256 JWT / firebase/php-jwt）
- Google Cloud Vision API（OCR）
- Claude API（claude-sonnet-4-20250514）
- Gmail API（OAuth2）

## よく使うコマンド

### Docker操作
```bash
# 起動
cd ~/sales_support
docker compose up -d

# 停止
docker compose down

# 状態確認
docker compose ps

# pgsql拡張確認
docker compose exec app php -m | grep pgsql
```

### キャッシュクリア
```bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear
docker compose exec app php artisan route:clear
docker compose exec app php artisan optimize:clear
```

### ログ確認
```bash
# ローカル
docker compose exec app tail -f storage/logs/laravel.log

# 本番VPS
docker exec sales_support_app tail -f /var/www/storage/logs/sales_sup-$(date +%Y-%m-%d).log
```

### マイグレーション
```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:status
```

### スケジューラ
```bash
# 手動実行
docker compose exec app php artisan schedule:run --verbose

# 登録確認
docker compose exec app php artisan schedule:list
```

## デプロイ手順

### ローカル → GitHub
```bash
cd ~/sales_support
git add .
git commit -m "feat: ..."
git push origin main
```

### VPS反映
```bash
ssh root@v133-18-42-139.vir.kagoya.net
cd /var/www/sales_support
git pull origin main
docker exec sales_support_app php artisan migrate --force
docker exec sales_support_app php artisan config:clear
docker exec sales_support_app php artisan cache:clear
```

## 重要な注意事項

### パーミション問題
コンテナ再起動後にログが書けなくなる場合：
```bash
docker compose exec -u root app chmod -R 777 /var/www/storage
docker compose exec -u root app chmod -R 777 /var/www/bootstrap/cache
```

### pgsql拡張
Dockerfileに`libzip-dev`と`pdo_pgsql`が含まれている。
再ビルドが必要な場合：
```bash
docker compose down
docker compose build --no-cache app
docker compose up -d
```

### git管理除外ファイル
```bash
# VPSのdocker-compose.ymlは上書き保護
git update-index --skip-worktree docker-compose.yml
```

### deals.status制約
`migrate:fresh`実行時に失われる。手動でSQL再適用が必要。

### Supabase接続
- Direct ConnectionはIPv6のみ → Session Pooler（aws-1-ap-northeast-1.pooler.supabase.com）を使用
- `PGSSLMODE=require`必須

### JWT検証
- Supabase Auth は ES256/ECC P-256
- `JWT::$leeway = 60`で時刻ずれを許容
- JWKSエンドポイント: `https://qkjceppkrsurrynqsuse.supabase.co/auth/v1/.well-known/jwks.json`

### メール自動同期
- `routes/console.php`で15分毎に`SyncEmailsJob`を実行
- VPS crontab: `* * * * * docker exec sales_support_app php artisan schedule:run`
- `Schedule::call()`で直接実行（キュー不要）
- `name()`は`withoutOverlapping()`の前に記述すること

## Supabase構成
| 環境 | プロジェクトID |
|------|--------------|
| 本番 | qkjceppkrsurrynqsuse |
| 開発 | smzoqpvaxznqcwrsgjju |

### Realtime有効テーブル
`emails` / `tasks` / `deals` / `activities` / `business_cards`

## VPS情報
- SSH: `ssh root@v133-18-42-139.vir.kagoya.net`
- GitLab Nginx（ポート443）→ Docker Nginx（ポート8090）
- Nginx設定: `/etc/gitlab/nginx-ai-mon.conf`
- カスタムDockerイメージ: `sales_support-app:with-pgsql`

## 実装済み機能
- 認証（Supabase Auth JWT）
- 顧客・担当者・商談・活動履歴・タスク管理
- 名刺管理（OCR・Supabase Storage）
- SES台帳（Excel取込・契約・精算管理）
- メール連携（Gmail API・自動同期・未読バッジ）
- 多テナント（GlobalScope）
- Realtime通知

## 次期開発候補
- 音声文字起こし（Whisper API + Claude要約）
- 勤務表・請求書管理
- Supabase RLS強化
- メール→商談自動紐付け（Claude API）
