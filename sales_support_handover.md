# 営業支援システム 引き継ぎドキュメント
## 最終更新: 2026/03/21

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

| 項目 | 技術 |
|---|---|
| フロントエンド | Next.js 15, TypeScript, Tailwind CSS, shadcn/ui |
| バックエンド | Laravel 11, PHP, Supabase Auth JWT認証 |
| データベース | Supabase PostgreSQL（Session Pooler経由） |
| 認証 | Supabase Auth（ES256 JWT / firebase/php-jwt） |
| ファイルストレージ | Supabase Storage（名刺画像） |
| リアルタイム | Supabase Realtime（tasks/deals/activities/cards） |
| OCR | Google Cloud Vision API |
| AI情報抽出 | Claude API（claude-sonnet-4-20250514） |
| メール連携 | Gmail API（Outlook → Gmail転送 + OAuth2） |
| 状態管理 | Zustand（authStore） |
| デプロイ | Vercel（Next.js）/ Kagoya VPS（Laravel Docker） |

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
├── emails/             メール管理
│   └── page.tsx        一覧・詳細（2ペインレイアウト）
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
├── BusinessCardController.php   名刺OCR・Supabase連携
├── GmailOAuthController.php     Gmail OAuth2認可・トークン保存
└── EmailController.php          メール一覧・詳細・同期・紐付け
Services/
├── ClaudeService.php                      Claude API情報抽出
├── BusinessCardRegistrationService.php    顧客・担当者自動登録
├── SupabaseStorageService.php             Supabase Storageアップロード
└── GmailService.php                       Gmail API（取得・同期・既読）
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
├── BusinessCard.php
├── GmailToken.php               Gmail OAuthトークン管理
└── Email.php                    受信メールキャッシュ
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

---

## 6. メール連携（Gmail API）

### 構成
```
outsource@aizen-sol.co.jp（Outlook）
  ↓ 自動転送
aizenoutsource@gmail.com（Gmail）
  ↓ Gmail API（OAuth2）
Laravel GmailService
  ↓
emailsテーブル（Supabase PostgreSQL）
  ↓
Next.js /emails ページ
```

### Gmail OAuth フロー
1. フロント → `GET /api/v1/gmail/redirect` → 認可URL取得（stateにuser_id埋め込み）
2. ユーザーがGoogleで認可
3. Google → `GET /api/v1/gmail/callback?code=...&state={user_id}`
4. LaravelがトークンをgmailTokensテーブルに保存
5. 「同期」ボタン → `POST /api/v1/emails/sync` → Gmail APIでメール取得・DB保存

### APIエンドポイント
| メソッド | パス | 説明 |
|---|---|---|
| GET | /api/v1/gmail/redirect | OAuth認可URL取得 |
| GET | /api/v1/gmail/callback | OAuthコールバック（認証不要） |
| GET | /api/v1/gmail/status | 接続状態確認 |
| DELETE | /api/v1/gmail/disconnect | 接続解除 |
| GET | /api/v1/emails | メール一覧 |
| POST | /api/v1/emails/sync | Gmail同期 |
| GET | /api/v1/emails/{id} | メール詳細（既読更新） |
| PATCH | /api/v1/emails/{id}/link | 商談・担当者紐付け |
| GET | /api/v1/emails/unread-count | 未読件数 |

### GCPプロジェクト設定
- プロジェクト: `sales-support`（Google Vision APIと同じ）
- Gmail API: 有効化済み
- OAuthクライアントID: `117456556358-ci3me2b8ml6cenija6mf424uejl295b7.apps.googleusercontent.com`
- テストユーザー: `aizenoutsource@gmail.com`
- スコープ: `gmail.readonly email profile`

### 注意点
- **受信のみ対応**（送信・返信は未実装）
- Outlook → Gmail転送のため数秒〜数分の遅延あり
- 同期は手動（「同期」ボタン）。自動同期は未実装

---

## 7. 多テナント実装

- `tenants`テーブル・`BelongsToTenant` trait（`app/Traits/BelongsToTenant.php`）
- `GlobalScope`でテナントデータを自動フィルタ
- `SetTenantContext` middlewareでテナントコンテキスト設定
- Laravel は `service_role` キーで Supabase に接続（RLSバイパス）
- Supabase側RLSは有効化済み（直接アクセス時の保護）

---

## 8. テストユーザー（パスワード全員: `password`）

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

※ ユーザーはSupabase Authとusersテーブル両方に登録済み（supabase_uidで紐付け）

---

## 9. 環境変数

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

### Laravel（`.env` 本番）
```env
DB_CONNECTION=pgsql
DB_HOST=aws-1-ap-northeast-1.pooler.supabase.com   # Session Pooler（IPv4対応）
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.qkjceppkrsurrynqsuse
DB_PASSWORD=sales_password_2026
DB_SSLMODE=require

### Laravel（`.env` ローカル・03/21 分離）
```env
DB_CONNECTION=pgsql
DB_HOST=aws-1-ap-northeast-1.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.smzoqpvaxznqcwrsgjju
DB_PASSWORD=DevPassword2026!
PGSSLMODE=require

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

# Gmail API
GMAIL_CLIENT_ID=117456556358-ci3me2b8ml6cenija6mf424uejl295b7.apps.googleusercontent.com
GMAIL_CLIENT_SECRET=GOCSPX-...              # 実際の値はVPS/.envを参照
GMAIL_REDIRECT_URI=https://sales.ai-mon.net/api/v1/gmail/callback  # 本番
# GMAIL_REDIRECT_URI=http://localhost:8090/api/v1/gmail/callback    # ローカル
```

---

## 10. Supabase構成
- **本番**
- **プロジェクト**: sales-support（東京リージョン）
- **URL**: https://qkjceppkrsurrynqsuse.supabase.co
- **DB接続**: Session Pooler（ポート5432・IPv4対応）
- **Storage バケット**: `business-cards`（PUBLIC）

- **ローカル**
- **プロジェクト**: sales-support-dev（東京リージョン）
- **URL**: https://smzoqpvaxznqcwrsgjju.supabase.co
- **DB接続**: Session Pooler（ポート5432・IPv4対応）
- **Storage バケット**: `business-cards`（PUBLIC）

- **Supabase環境分離・SeederでAuth自動登録対応**

### Realtime 有効テーブル
`tasks` / `deals` / `activities` / `business_cards`（`supabase_realtime` publication に登録済み）

### RLSポリシー
| 操作 | ロール |
|---|---|
| SELECT | public（全員OK） |
| INSERT | authenticated（service_roleはバイパス） |
| UPDATE | authenticated |
| DELETE | authenticated |

※ Laravel は service_role キーで接続するため RLS をバイパス。テナント分離は Laravel の GlobalScope が担当。

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
GitLabのNginx（ポート443）がリバースプロキシとして動作：
- 設定ファイル: `/etc/gitlab/nginx-ai-mon.conf`
- `https://sales.ai-mon.net` → `http://localhost:8090`（Docker Nginx）へ転送

### Docker コンテナ構成
```
sales_support_app    # PHP-FPM（pdo_pgsql拡張入りカスタムイメージ）
sales_support_nginx  # Nginx（ポート8090）
sales_support_db     # MySQL（ローカル開発用のみ）
```

### ⚠️ VPS のカスタムDockerイメージ
本番VPSの `sales_support_app` は `sales_support-app:with-pgsql` というカスタムイメージを使用（`docker commit` で作成）。

`docker-compose.yml`（VPS版）は `git update-index --skip-worktree` で git 管理から除外しており、git pull で上書きされない。

```bash
# VPS の docker-compose.yml（抜粋）
app:
  image: sales_support-app:with-pgsql  # カスタムイメージ
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
docker exec sales_support_app php artisan migrate --force
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
| メール連携（Gmail API・受信・一覧表示） | ✅ |

---

## 17. 次期開発候補

| 優先度 | 機能 |
|---|---|
| 高 | 音声文字起こし（商談記録） |
| 高 | メール自動同期（定期バッチ・SyncEmailsJob） |
| 中 | メール → 商談・担当者自動紐付け（Claude API活用） |
| 中 | メール返信・送信（Outlook SMTP連携） |
| 中 | カレンダー連携（Google Calendar） |
| 中 | レポート・分析ダッシュボード強化 |
| 中 | CSV インポート・エクスポート |
| 中 | Supabase Auth → RLSによるテナント分離強化 |
| 低 | SESマッチング機能（Phase 2） |
| 低 | 売上予測AI |

## 18. トラブル対応

ローカルで動かない場合のチェックリスト
| 確認項目　　　　　　| コマンド
|------------------|------------------------------------------------------------------------------------|
| DB接続　	　　　　 | docker compose exec app php artisan tinker --execute="DB::connection()->getPdo();"
|                  |                         "echo DB::connection()->getPdo() ? 'DB接続OK' : 'DB接続NG';"
| CORS設定　　　　　 | grep FRONTEND_URL ~/sales_support/.env
| Supabaseバケット　| ダッシュボードで business-cards バケット存在確認
| ログ確認	　　　　 | docker compose exec app tail -f storage/logs/laravel.log
| キャッシュクリア　 |  docker compose exec app php artisan config:clear
