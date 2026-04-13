# CLAUDE.md - sales_support（Laravel API）

# 開発方針

## 応答言語
- ユーザーが別言語を指定しない限り、日本語で回答する。

## 基本ワークフロー
- 曖昧な点は推測せず、ファイルや実行結果を確認する。
- 破壊的、または巻き戻し困難な操作の前には、必ずユーザーの明示同意を取る。
- 最終報告では、変更点・確認内容・未検証事項を簡潔にまとめる。

## マルチエージェント運用
- 並列調査や役割分担が有効な場合は、agent team を作成してよい。
- 調査・レビュー・比較検討のように独立して進められる作業では、複数の teammate に分担して進める。
- 実装を伴う大きな作業では、必要に応じて team の人数や役割を増やしてよい。
- team を作る場合は、各 teammate の役割が重複しないように分ける。
- split panes を使える環境では、team 作成時は split panes を優先する。

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
docker compose exec app tail -f storage/logs/sales_sup-$(date +%Y-%m-%d).log
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

## 事業概要（確認省略のための固定知識）
- **事業内容**: SES企業。IT技術者と発注企業（IT会社）をマッチング・提案
- **主なフロー**: 技術者紹介メール受信 → スコアリング → マッチ案件を特定 → 提案メール送信
- **目標**: 月1,200社への一括配信（現在AWS SES本番審査待ち・東京リージョン）
- **送信メール**: B2Bトランザクションメール（取引先IT企業の担当者宛）

## 確定済み設計判断
- 希望単価なし・35万/月未満の技術者 → 除外（`no_unit_price` / `unit_price_too_low`）
- マッチ案件表示条件: `案件.unit_price_max >= 技術者.unit_price_max`
- 送信履歴は `delivery_campaigns` + `delivery_send_histories` で一元管理
  - send_type: `delivery` / `proposal` / `matching_proposal` / `engineer_proposal`
- メール送信: AWS SES ap-northeast-1（東京）DKIM検証済み
- 全件再スコア: 添付解析スキップ・上限なし・600秒タイムアウト
- `storage/api-docs/` はgitignore済み（自動生成ファイル）

## 開発環境（職場・自宅 併用）
- 職場・自宅ともに WSL2 + Docker 環境
- コード共有: GitHub（git push/pull）
- `.env` 共有: `.env_backup` をセキュアな方法で手動同期（gitには含めない）
- `memory.db` 共有: 下記「長期記憶の参照方法」参照

## 長期記憶の参照方法
過去のセッションで議論した設計判断・トラブル対応は以下で検索できる:
```bash
cd ~/memory_engine
uv run python search_memory.py "検索したい内容" --project sales_support
```
