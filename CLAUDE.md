# CLAUDE.md - sales_support（Laravel API）

## プロジェクト概要
SES企業向け営業支援システムのバックエンドAPI。
Laravel 11 + Supabase PostgreSQL + Docker構成。

## 技術スタック
- PHP / Laravel 11
- Supabase PostgreSQL（Session Pooler経由）
- Supabase Auth（ES256 JWT / firebase/php-jwt）
- Supabase Storage（名刺画像）
- Google Cloud Vision API（OCR）
- Claude API（claude-sonnet-4-20250514）
- Gmail API（OAuth2・受信のみ）
- Docker（PHP-FPM + Nginx）

## ローカル起動
```bash
cd ~/sales_support
docker compose up -d
# API: http://localhost:8090
```

## よく使うコマンド
```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear
docker compose exec app tail -f storage/logs/laravel.log
```

## 重要な注意点
- JWT検証: ES256（ECC P-256）、leeway=60秒
- DB接続: Session Pooler（aws-1-ap-northeast-1.pooler.supabase.com:5432）IPv6非対応のため
- VPS本番のdocker-compose.ymlはskip-worktreeで保護済み（git pullで上書きされない）
- Laravel は service_role キーで Supabase 接続（RLSバイパス）
- テナント分離はGlobalScopeが担当
- Schedule::call()でジョブ直接実行（Queueワーカー不要）
- Supabase Realtimeループ防止: INSERTイベントのみ購読

## ディレクトリ構成
```
app/
├── Http/Controllers/Api/   # 各APIコントローラ
├── Services/               # ClaudeService, GmailService等
├── Http/Middleware/        # SupabaseAuth, SetTenantContext
└── Models/                 # Tenant, User, Customer, Deal等
```

## 本番デプロイ
```bash
git push origin main
ssh root@v133-18-42-139.vir.kagoya.net
cd /var/www/sales_support
git pull origin main
docker exec sales_support_app php artisan migrate --force
docker exec sales_support_app php artisan config:clear
```

## 長期記憶の参照方法
過去のセッションで議論した設計判断・トラブル対応は以下で検索できる:
```bash
cd ~/memory_engine
uv run python search_memory.py "検索したい内容" --project sales_support
```
