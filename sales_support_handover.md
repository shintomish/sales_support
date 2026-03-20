# 営業支援システム 引き継ぎドキュメント
## 最終更新: 2026/03/20

---

## 1. プロジェクト概要

SES企業向け営業支援システム。Laravel（API）+ Next.js（フロントエンド）の構成。
Sansanの機能を参考に、独自開発で営業活動を効率化する。

---

## 2. 環境構成

| 環境 | サービス | URL |
|---|---|---|
| ローカル WSL2 | Next.js | http://localhost:3000 |
| ローカル Docker | Laravel API | http://localhost:8090 |
| 本番 Vercel | Next.js | https://app.ai-mon.net |
| 本番 Kagoya VPS | Laravel API | https://sales.ai-mon.net |

### リポジトリ
- Next.js: `~/sales_support_next`（GitHub連携・Vercel自動デプロイ）
- Laravel: `~/sales_support`（VPS: `/var/www/sales_support`）

### VPS接続
```bash
ssh root@v133-18-42-139.vir.kagoya.net
```

### ローカル起動
```bash
# Laravel（Docker）
cd ~/sales_support
docker compose up -d

# Next.js
cd ~/sales_support_next
npm run dev
```

---

## 3. 技術スタック

| 項目             | 技術 |
|-----------------|-------------------------------------------------|
| フロントエンド    | Next.js 15, TypeScript, Tailwind CSS, shadcn/ui |
| バックエンド      | Laravel 11, PHP, Supabase Auth JWT認証           |
| データベース      | Supabase PostgreSQL（Session Pooler経由）        |
| 認証             | Supabase Auth（ES256 JWT / firebase/php-jwt）   |
| ファイルストレージ | Supabase Storage（名刺画像）                     |
| リアルタイム     | Supabase Realtime（tasks/deals/activities/cards）|
| OCR             | Google Cloud Vision API                        |
| AI情報抽出       | Claude API（claude-sonnet-4-20250514）          |
| 状態管理         | Zustand（authStore）                            |
| デプロイ         | Vercel（Next.js）/ Kagoya VPS（Laravel Docker） |

---

## 4. 主要ファイル構成

### Next.js（`~/sales_support_next/src/`）
```
app/
├── dashboard/          ダッシュボード
├── customers/          顧客管理
├── contacts/           担当者管理
├── deals/              商談管理
├── activities/         活動履歴
├── tasks/              タスク管理
├── business-cards/     名刺管理
│   ├── page.tsx        一覧
│   ├── create/         アップロード
│   └── [id]/           詳細・編集
├── login/              ログイン
lib/
├── axios.ts            APIクライアント（Supabase JWTを自動付与）
├── supabase.ts         Supabaseクライアント
store/
└── authStore.ts        Zustand認証ストア（Supabase Auth対応）
components/
├── Sidebar.tsx         サイドメニュー
├── SidebarWrapper.tsx  レイアウトラッパー・認証ガード
├── RealtimeToast.tsx   リアルタイム通知トースト
└── NotificationToast.tsx 期限切れタスク通知
hooks/
└── useRealtimeNotifications.ts  Supabase Realtimeフック
proxy.ts                ルートガード（Supabase Auth対応）
```

### Laravel（`~/sales_support/app/`）
```
Http/Controllers/Api/
├── AuthController.php
├── CustomerController.php
├── ContactController.php
├── DealController.php
├── ActivityController.php
├── TaskController.php
└── BusinessCardController.php  名刺OCR・Supabase連携
Services/
├── ClaudeService.php            Claude API情報抽出
├── BusinessCardRegistrationService.php  顧客・担当者自動登録
└── SupabaseStorageService.php   Supabase Storageアップロード
Http/Middleware/
├── SupabaseAuth.php             Supabase JWT検証（ES256対応）
├── SetTenantContext.php         テナントコンテキスト設定
└── LogUserActivity.php          ユーザー操作ログ
Models/
├── Tenant.php
├── User.php                     supabase_uidカラム追加済み
├── Customer.php
├── Contact.php
├── Deal.php
├── Activity.php
├── Task.php
└── BusinessCard.php
```

---

## 5. 認証の仕組み（Supabase Auth）

```
ログイン（Next.js）
→ supabase.auth.signInWithPassword()
→ Supabase Auth が JWT（ES256）を発行
→ authStore に保存（localStorageベース）

API呼び出し
→ axios.tsのインターセプターでSupabaseセッションからJWTを取得
→ Authorization: Bearer {JWT} ヘッダーに付与
→ LaravelのSupabaseAuthミドルウェアがJWT検証
→ supabase_uid でusersテーブルからユーザー特定
→ auth()->setUser($user) でLaravel認証ユーザーをセット
```

### JWT検証の注意点
- Supabase は現在 **ECC P-256（ES256）** でJWT署名
- JWKS エンドポイント: `https://qkjceppkrsurrynqsuse.supabase.co/auth/v1/.well-known/jwks.json`
- `JWT::$leeway = 60` で時刻ずれを60秒許容（VPS環境対策）

### SidebarWrapper による認証ガード
- 未認証 + 非ログインページ → `/login` へリダイレクト
- 認証済み + `/login` → `/dashboard` へリダイレクト

---

## 6. 多テナント実装

- `tenants`テーブル・`BelongsToTenant` trait
- `GlobalScope`でテナントデータを自動フィルタ
- `SetTenantContext` middlewareでテナントコンテキスト設定
- Laravel は `service_role` キーで Supabase に接続（RLSバイパス）
- Supabase側RLSは有効化済み（直接アクセス時の保護）

---

## 7. テストユーザー（パスワード全員: `password`）

| メールアドレス | ロール | テナント |
|---|---|---|
| shintomi.sh@gmail.com | super_admin | テナント1（tenant_id=1） |
| suzuki.k@izen-solution.jp | tenant_admin | テナント1 |
| sato.m@izen-solution.jp | tenant_user | テナント1 |
| takahashi.y@izen-solution.jp | tenant_user | テナント1 |
| ito.n@towa-shoji.co.jp | tenant_admin | テナント2 |
| watanabe.s@towa-shoji.co.jp | tenant_user | テナント2 |
| nakamura.t@towa-shoji.co.jp | tenant_user | テナント2 |
| kobayashi.t@next-stage.jp | tenant_admin | テナント3 |
| kato.m@next-stage.jp | tenant_user | テナント3 |
| yoshida.n@next-stage.jp | tenant_user | テナント3 |

※ ユーザーは Supabase Auth と users テーブル両方に登録済み（supabase_uid で紐付け）

---

## 8. 環境変数

### Next.js（`.env.local`）
```env
NEXT_PUBLIC_API_URL=http://localhost:8090
NEXT_PUBLIC_SUPABASE_URL=https://qkjceppkrsurrynqsuse.supabase.co
NEXT_PUBLIC_SUPABASE_ANON_KEY=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

### Next.js（Vercel 本番環境変数）
```env
NEXT_PUBLIC_API_URL=https://sales.ai-mon.net
NEXT_PUBLIC_SUPABASE_URL=https://qkjceppkrsurrynqsuse.supabase.co
NEXT_PUBLIC_SUPABASE_ANON_KEY=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

### Laravel（`.env` ローカル・本番共通）
```env
DB_CONNECTION=pgsql
DB_HOST=aws-1-ap-northeast-1.pooler.supabase.com   # Session Pooler（IPv4対応）
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.qkjceppkrsurrynqsuse
DB_PASSWORD=sales_password_2026
DB_SSLMODE=require

ANTHROPIC_API_KEY=sk-ant-api03-...
CLAUDE_MODEL=claude-sonnet-4-20250514

GOOGLE_APPLICATION_CREDENTIALS=/var/www/storage/credentials/google-vision.json  # 本番
# GOOGLE_APPLICATION_CREDENTIALS=/path/to/local/google-vision.json              # ローカル

SUPABASE_URL=https://qkjceppkrsurrynqsuse.supabase.co
SUPABASE_SERVICE_ROLE_KEY=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
SUPABASE_BUCKET=business-cards
SUPABASE_JWKS_URL=https://qkjceppkrsurrynqsuse.supabase.co/auth/v1/.well-known/jwks.json
SUPABASE_ANON_KEY=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...

FRONTEND_URL=https://app.ai-mon.net          # 本番
# FRONTEND_URL=http://localhost:3000          # ローカル
SANCTUM_STATEFUL_DOMAINS=app.ai-mon.net      # 本番
# SANCTUM_STATEFUL_DOMAINS=localhost:3000     # ローカル
```

---

## 9. Supabase構成

- **プロジェクト**: sales-support（東京リージョン）
- **URL**: https://qkjceppkrsurrynqsuse.supabase.co
- **DB接続**: Session Pooler（ポート5432・IPv4対応）
- **Storage バケット**: `business-cards`（PUBLIC）

### Realtime 有効テーブル
`tasks` / `deals` / `activities` / `business_cards`
（`supabase_realtime` publication に登録済み）

### RLSポリシー
| 操作 | ロール |
|---|---|
| SELECT | public（全員OK） |
| INSERT | authenticated（service_roleはバイパス） |
| UPDATE | authenticated |
| DELETE | authenticated |

※ Laravel は service_role キーで接続するため RLS をバイパス。
テナント分離は Laravel の GlobalScope が担当。

### アップロードフロー（名刺）
```
ブラウザ → Laravel API（multipart/form-data）
→ SupabaseStorageService（service_roleキーでRLSバイパス）
→ Supabase Storage に保存
→ 公開URLをDBに保存（image_path）
→ OCR処理（Google Vision API）
→ Claude APIで情報抽出
→ 顧客・担当者自動登録
```

---

## 10. Realtime通知の仕組み

### 購読チャンネル
`realtime:tenant:{tenant_id}` でテナント分離

### 通知内容
| テーブル | イベント | 通知例 |
|---|---|---|
| tasks | INSERT/UPDATE/DELETE | タスク「提案書作成」のステータスが「完了」に変更されました |
| deals | INSERT/UPDATE/DELETE | 🎉 商談「クラウド移行PJ」が成約しました！ |
| activities | INSERT/UPDATE/DELETE | 新しい活動「初回商談」が記録されました |
| business_cards | INSERT | 📇 名刺のアップロードが完了しました |

### トースト通知
- 画面右下に表示（5秒で自動消去）
- `src/components/RealtimeToast.tsx`
- `src/hooks/useRealtimeNotifications.ts`

---

## 11. 名刺画像URL後方互換対応

```tsx
src={card.image_path.startsWith('http')
  ? card.image_path                    // Supabase URL
  : `${process.env.NEXT_PUBLIC_API_URL}/storage/${card.image_path}`} // 旧ローカルパス
```

---

## 12. VPS固有設定

### 本番Nginxの構成
GitLab の Nginx（ポート443）がリバースプロキシとして動作：
- 設定ファイル: `/etc/gitlab/nginx-ai-mon.conf`
- `https://sales.ai-mon.net` → `http://localhost:8090`（Docker Nginx）へ転送

### Docker コンテナ構成
```
sales_support_app    # PHP-FPM（pdo_pgsql拡張入りカスタムイメージ）
sales_support_nginx  # Nginx（ポート8090）
sales_support_db     # MySQL（ローカル開発用のみ）
```

### ⚠️ VPS のカスタムDockerイメージ
本番VPSの `sales_support_app` は `sales_support-app:with-pgsql` という
カスタムイメージを使用（`docker commit` で作成）。

`docker-compose.yml`（VPS版）は `git update-index --skip-worktree` で
git 管理から除外しており、git pull で上書きされない。

```yaml
# VPS の docker-compose.yml（抜粋）
app:
  image: sales_support-app:with-pgsql  # カスタムイメージ
```

### VPS 完全再構築時の手順
```bash
docker compose up -d
docker exec -u root sales_support_app bash -c \
  "apt-get update -qq && apt-get install -y --no-install-recommends libpq-dev \
  && docker-php-ext-install pdo_pgsql pgsql"
docker commit sales_support_app sales_support-app:with-pgsql

# docker-compose.ymlをimage版に変更
python3 -c "
content = open('docker-compose.yml').read()
content = content.replace(
    '    build:\n      context: .\n      dockerfile: Dockerfile',
    '    image: sales_support-app:with-pgsql'
)
open('docker-compose.yml', 'w').write(content)
"
git update-index --skip-worktree docker-compose.yml
git update-index --skip-worktree app/Http/Middleware/SupabaseAuth.php
git update-index --skip-worktree Dockerfile
git update-index --skip-worktree config/cors.php
git update-index --skip-worktree nginx/conf.d/default.conf
```

### php-fpm専用プール（`/etc/php-fpm.d/sales_support.conf`）
```ini
[sales_support]
user = nginx
group = nginx
listen = /run/php-fpm/sales_support.sock
chdir = /var/www/sales_support
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 20M
```

### Google Vision API認証
- 本番パス: `/var/www/storage/credentials/google-vision.json`
- ※ Laravelのbase_pathは `/var/www`（`sales_support`なし）

---

## 13. ログ

```bash
# ローカル
docker compose exec app tail -f storage/logs/laravel.log

# 本番VPS（コンテナ内）
docker exec sales_support_app tail -f /var/www/storage/logs/sales_sup-$(date +%Y-%m-%d).log
```

---

## 14. デプロイ手順

### Next.js（Vercel自動デプロイ）
```bash
cd ~/sales_support_next
git add .
git commit -m "feat: ..."
git push origin main
# → Vercel自動デプロイ
```

### Laravel（VPS）
```bash
# ローカルでpush
cd ~/sales_support
git push origin main

# VPSでpull（docker-compose.ymlは skip-worktree で保護済み）
ssh root@v133-18-42-139.vir.kagoya.net
cd /var/www/sales_support
git pull origin main
docker exec sales_support_app php artisan config:clear
docker exec sales_support_app php artisan cache:clear
```

---

## 15. Seeder

```bash
# ローカル
docker compose exec app php artisan db:seed --class=TestDataSeeder
docker compose exec app php artisan db:seed --class=BusinessCardSeeder

# 本番（Supabase上で実行）
docker exec sales_support_app php artisan db:seed --class=TestDataSeeder
```

---

## 16. 実装済み機能

| 機能 | 状態 |
|---|---|
| ログイン・ログアウト（Supabase Auth） | ✅ |
| 顧客管理 CRUD | ✅ |
| 担当者管理 CRUD | ✅ |
| 商談管理 CRUD | ✅ |
| 活動履歴 CRUD | ✅ |
| タスク管理 CRUD | ✅ |
| 名刺管理（OCR・Supabase Storage） | ✅ |
| 多テナント | ✅ |
| ユーザー操作ログ | ✅ |
| ダッシュボード | ✅ |
| 期限切れタスク通知 | ✅ |
| 全一覧画面スクロール固定・奇偶行背景色 | ✅ |
| DB移行（MySQL → Supabase PostgreSQL） | ✅ |
| 認証移行（Sanctum → Supabase Auth） | ✅ |
| Realtimeリアルタイム通知 | ✅ |
| 本番デプロイ（app.ai-mon.net） | ✅ |

---

## 17. 次期開発候補

| 優先度 | 機能 |
|---|---|
| 高 | 音声文字起こし（商談記録） |
| 高 | メール連携（Gmail/Outlook） |
| 中 | カレンダー連携（Google Calendar） |
| 中 | レポート・分析ダッシュボード強化 |
| 中 | CSV インポート・エクスポート |
| 中 | Supabase Auth → RLSによるテナント分離強化 |
| 低 | SESマッチング機能（Phase 2） |
| 低 | 売上予測AI |
