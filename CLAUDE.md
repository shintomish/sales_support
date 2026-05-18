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
- Claude API（claude-sonnet-4-6 / `config('services.anthropic.model')` 経由）
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
- **新規テーブル作成 migration では `up()` 内に `DB::statement('ALTER TABLE public.{name} ENABLE ROW LEVEL SECURITY')` を必ず追加**（Supabase の PostgREST 経由で外部公開されるのを防ぐため。policy は作らず default deny で運用。Laravel は service_role でバイパス）
- **Supabase Data API 用 GRANT（2026-10-30 強制適用）**: 新規テーブル作成 migration では、Data API 経由でアクセスする可能性があるテーブルに対し以下を明示する。Supabase 公式変更により public スキーマのデフォルト権限が廃止されるため、grant を書かないとフロントの Realtime / supabase-js から読めなくなる。
  ```php
  // Realtime / supabase-js でフロントから読む場合
  DB::statement('GRANT SELECT ON public.{name} TO authenticated');
  // Laravel バックエンドは元々 service_role で全権限を持つが念のため明示
  DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON public.{name} TO service_role');
  ```
  Realtime/Data API でフロントから触らないテーブル（Laravel 経由のみ）は authenticated への grant 不要。

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

### docker-compose の構成（2026-05-04〜）
- `docker-compose.yml` は **共通設定 + 開発時デフォルト**（test-postgres 含む）
- `docker-compose.override.yml` は **環境別の上書き**（本番固有設定）。git 管理外。
- 本番固有設定（事前ビルド image / healthcheck / test-postgres 無効化）は本番 VPS の
  `docker-compose.override.yml` に配置。テンプレートは `docker-compose.override.yml.example`。

### 本番初回セットアップ（または compose 構成変更時）
```bash
ssh root@v133-18-42-139.vir.kagoya.net
cd /var/www/sales_support
# 既存 docker-compose.yml が skip-worktree されている場合は解除
git update-index --no-skip-worktree docker-compose.yml
git pull origin main
# 本番固有設定を override.yml として配置（git 管理外）
cp docker-compose.override.yml.example docker-compose.override.yml
# マージ結果を確認してから反映
docker compose config | head
docker compose up -d
```

## 事業概要（確認省略のための固定知識）
- **事業内容**: SES企業。IT技術者と発注企業（IT会社）をマッチング・提案
- **主なフロー**: 技術者紹介メール受信 → スコアリング → マッチ案件を特定 → 提案メール送信
- **目標**: 日次12,000件・月次240,000件の一括配信
- **送信メール**: B2Bトランザクションメール（取引先IT企業の担当者宛）

## 確定済み設計判断
- 希望単価なし・35万/月未満の技術者 → 除外（`no_unit_price` / `unit_price_too_low`）
- マッチ案件表示条件: `案件.unit_price_max >= 技術者.unit_price_max`
- 送信履歴は `delivery_campaigns` + `delivery_send_histories` で一元管理
  - send_type 一覧 (2026-05-18 時点):
    - `delivery` — /deliveries 単発送信
    - `proposal` — 案件メールから個別提案 (ProjectMailController::sendProposal)
    - `matching_proposal` — engineer-mail 詳細から案件への提案 (sendProposalFromEms)
    - `engineer_proposal` — 技術者メールから個別提案 (EngineerMailController::sendProposal / sendProposalFromPms)
    - `bulk` — 案件メールから「まとめて提案」(matching/[id]・1宛先で複数技術者を packing)
    - `engineer_proposal_bulk` — 技術者メールから「まとめて提案」(engineer-mails/[id]・1 BP に複数案件 packing)
  - 提案スレッド系の whereIn を増減する時は以下 4 箇所を必ず同期する: `DeliveryCampaignController::index`(exclude_proposals) / `DeliveryCampaignController::proposalThreads`(本体+campaignsByThread) / `ProjectMailController::thread` / `EngineerMailController::thread`
- メール送信: AWS SES（東京リージョン・本番承認済み）50,000件/日・14件/秒（2026-04-17承認）
- 全件再スコア: 添付解析スキップ・上限なし・600秒タイムアウト
- `storage/api-docs/` はgitignore済み（自動生成ファイル）

## 開発環境（職場・自宅 併用）
- 職場・自宅ともに WSL2 + Docker 環境
- コード共有: GitHub（git push/pull）
- `.env` 共有: 手動同期（gitには含めない）
- `memory.db` 共有: Dropbox経由でリアルタイム同期（職場・自宅ともにシンボリックリンク）
  - 実体: `/mnt/c/Users/<user>/Dropbox/Public/Book/03_Aizen/990_Sales_Support/memory_engine/memory.db`
  - 職場・自宅共通: `ln -s "/mnt/c/Users/<user>/Dropbox/.../memory_engine/memory.db" ~/memory_engine/memory.db`
  - `<user>` は WSL2 Windows ユーザー名（自宅: `NAKA-MINI` / 職場は別）
- Claude Code auto-memory 共有: 同じく Dropbox 経由で symlink 化
  - 実体: `/mnt/c/Users/<user>/Dropbox/Public/Book/03_Aizen/990_Sales_Support/claude_memory/`
  - 職場・自宅共通: `ln -s "/mnt/c/Users/<user>/Dropbox/.../claude_memory" ~/.claude/projects/-home-shintomi-sales-support/memory`
  - 既存 memory ディレクトリがある場合は `mv ... memory.bak.YYYYMMDD` で退避してから symlink を貼る

## 長期記憶の参照方法
過去のセッションで議論した設計判断・トラブル対応は以下で検索できる:
```bash
cd ~/memory_engine
uv run python search_memory.py "検索したい内容" --project sales_support
```
