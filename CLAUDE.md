# CLAUDE.md - sales_support（Laravel API）

# 開発方針

## Advisor へ（本質の番人）
Advisor: あなたはこのプロジェクトの「本質の番人」です。方針決定前・行き詰まり時・完了宣言前の節目で、executor の判断を次の 3 軸に照らして評価してください。ズレていれば短く指摘し、軌道修正させてください（講評は簡潔に）。
1. **事務の信用に直結する本番システム**。破壊的操作・所有/権限変更・本番ログ書込・本番DB変更は特に慎重に。可逆性と確認の有無を必ず問う。
2. **自社/外部の技術者・案件は完全中立**（score 順のみ）。中立性を崩す変更（恣意的な優遇・ペナルティの自己強化ループ等）は止める。
3. **既存の同期・防御制約を破らない**。send_type 4 箇所同期 / 新規テーブルの RLS+GRANT / emails 一括書き込みの LIMIT バッチ等、明文化された制約を黙って破る変更は本質違反として指摘する。

## Advisor 活用ルール（executor 向け）
- 複数ファイル変更・新依存追加・本番に触れる作業の着手前に方針を相談する。
- 同じエラーを 2 回繰り返したら相談する。
- 完了を宣言する前に最終相談する。

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

### 本番初回セットアップ / compose 構成変更時
→ 手順は `docs/ops-setup.md` 参照（skip-worktree 解除・override.yml 配置など）。

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
    - `self_reply` — /emails からの個別返信 (EmailController::reply)。2026-06-06 に `delivery` から分離。専用「返信履歴」タブ (deliveries `tab=replies`・index に `send_type=self_reply` 指定) で表示
  - 提案スレッド系の whereIn を増減する時は以下 4 箇所を必ず同期する: `DeliveryCampaignController::index`(exclude_proposals) / `DeliveryCampaignController::proposalThreads`(本体+campaignsByThread) / `ProjectMailController::thread` / `EngineerMailController::thread`
  - `'delivery'` (一斉配信) は提案スレッド系の whereIn には **含めない** (1対多なのでスレッド概念に合わない・一斉配信履歴タブのみで表示)
  - `'self_reply'` (/emails 個別返信) は `exclude_proposals` の whereNotIn に **含める** (一斉配信履歴から除外し「返信履歴」タブにのみ表示)。提案スレッド系ではないので上記4箇所の同期対象外
- メール送信: AWS SES（東京リージョン・本番承認済み）50,000件/日・14件/秒（2026-04-17承認）
- 全件再スコア: 添付解析スキップ・上限なし・600秒タイムアウト
- `storage/api-docs/` はgitignore済み（自動生成ファイル）

## 開発環境（職場・自宅 併用）
- 職場・自宅ともに WSL2 + Docker。コード=GitHub / `.env`=手動同期 / `memory.db`・auto-memory・`.claude` 設定=Dropbox 経由 symlink で共有。
- symlink の実体パス・貼り直し手順・両環境同時編集の注意は `docs/ops-setup.md` 参照。

## 長期記憶の参照方法
過去のセッションで議論した設計判断・トラブル対応は以下で検索できる:
```bash
cd ~/memory_engine
uv run python search_memory.py "検索したい内容" --project sales_support
```
