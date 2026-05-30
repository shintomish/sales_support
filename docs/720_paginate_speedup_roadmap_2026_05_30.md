# Paginate 高速化ロードマップ

## 概要

全 18 件の severity 分布:

| severity | 件数 | エンドポイント数 |
|----------|------|------------------|
| critical | 0 | 0 |
| high | 2 | `/delivery-campaigns`, `/public-projects` |
| medium | 4 | `/delivery-addresses`, `/activities`, `/project-mails`, `/emails` |
| low | 10 | `/customers`, `/proposal-threads`, `/tasks`, `/deals`, `/contacts`, `/engineer-mails`, `/engineers`, `/invoices`, `/invoice-send-histories`, `/ses-contracts` |
| none | 2 | `/cards`, `SendHistoryController(未ルーティング)` |

---

## Critical

該当なし。

---

## High

### GET /api/v1/delivery-campaigns
- **summary**: デフォルトソート列 `sent_at` に index 無し。search 時 `orWhereHas('sendHistories')` が非index列 (email/name) に ILIKE。sort_by=sent_by/project_title が orderByRaw 相関サブクエリでページ内 N 回評価。
- **root_cause**: delivery_campaigns に `tenant_id / project_mail_id / engineer_mail_source_id` 単独 index のみ。`(tenant_id, sent_at DESC)` 複合 index 不在。delivery_send_histories.email/name に index/trgm GIN なし。月240k想定で全走査リスク。
- **improvement**: (A) `(tenant_id, sent_at DESC)` と `(tenant_id, send_type, sent_at DESC)` を CONCURRENTLY 追加。(B) delivery_send_histories.email/name に pg_trgm GIN または whereHas→whereIn(subquery) 書き換え。(C) sort_by=sent_by/project_title を leftJoin + orderBy に変えて相関サブクエリ排除。(D) フロント SWR + staleTime 30〜60s。
- **effort**: medium

### GET /api/v1/public-projects
- **summary**: `formatProject` が `projectMailSource.email` を参照しているが `with()` に未含有。カンバン view が `per_page=200` を投げるため 1 リクエスト最大 400 件の N+1 が顕在化。
- **root_cause**: `PublicProjectController::index` の `with()` に `'projectMailSource.email'` が欠落。`formatProject` で `from_address/from_name/sales_contact/body_text` を読むため lazy load N+1 発生。`emails.body_text` は TOAST 化された大カラムでペイロード肥大化も招く。
- **improvement**: (1) `with('projectMailSource.email:id,from_address,from_name,body_text')` を追加。(2) 一覧で `body_text` 不要なら formatProject から omit してペイロード縮小。(3) カンバン用 `per_page=200` を 50-100 に抑えるか軽量 DTO を導入。(4) `posted_by_customer_id` / `(tenant_id, work_style)` に index 追加検討。
- **effort**: small

---

## Medium

### GET /api/v1/delivery-addresses
- **summary**: ILIKE '%search%' が trgm GIN 無しで Seq Scan。1 リクエストあたり count(*) を 3 回実行。per_page 上限なし。
- **root_cause**: index が PK/tenant_id/unique(tenant_id,email)/unsubscribe_token のみ。totalCount/activeCount で COUNT が追加 2 回。sort_by 用 `(tenant_id, <col>)` 複合 index 不在。
- **improvement**: (1) per_page を max:500 で validate。(2) totalCount/activeCount を `SUM(CASE WHEN is_active...)` 1 本にまとめ 60s Cache::remember 化。(3) 必要に応じ `(tenant_id, is_active/name/email)` 複合 index 追加。(4) 大規模テナントのみ pg_trgm GIN (email/name)。(5) 中長期で keyset 化。
- **effort**: small

### GET /api/v1/activities
- **summary**: `(tenant_id, activity_date DESC)` 複合 index が無く、search 時の ILIKE も trgm 未整備。Offset paginate の典型劣化形。
- **root_cause**: 明示 index 無く foreignId の単独 btree のみ。デフォルトソート `WHERE tenant_id=? ORDER BY activity_date DESC` 用 index 不在。subject/content の両側ワイルドカード ILIKE が Seq Scan 必須。
- **improvement**: (1) `CREATE INDEX CONCURRENTLY activities_tenant_date_idx ON activities(tenant_id, activity_date DESC) WHERE deleted_at IS NULL`。(2) subject に pg_trgm GIN (content GIN は書込コスト要評価)。(3) 将来 cursorPaginate (activity_date, id) 化。
- **effort**: small

### GET /api/v1/project-mails
- **summary**: 通常時は `(tenant_id, received_at)` でカバーされ低リスク。search 時のみ project_mail_sources の text 列 ILIKE が Seq Scan + orWhereHas EXISTS の OR 合成で計画悪化。
- **root_cause**: paginate() の OFFSET+COUNT(*)。title/customer_name/work_location/sales_contact に trgm GIN 不在。`orWhereHas('email', ...)` で OR EXISTS 連結が index 選択を阻害。
- **improvement**: (A) customer_name と title に pg_trgm GIN を CONCURRENTLY 追加 (書込負荷を踏まえ 2 列に限定)。(B) simplePaginate or received_at+id keyset 化。(C) フロント SWR + staleTime 30s。(D) search_body と同様 3 文字未満は ILIKE skip。
- **effort**: small

### GET /api/v1/emails
- **summary**: Offset+COUNT と self_owner/mail_scope=self 時の to_address ILIKE/正規表現 substring (index 無し) が深ページ・自社タブで主要コスト。
- **root_cause**: paginate() の COUNT(*) が 105k 行 + 複合フィルタで重い。self scope の `lower(substring(to_address ...))` と `to_address ILIKE` が Seq Scan 確定。is_read 系 index は HOT 化のため 2026-05-28 に削除済で unread=1 も Seq Scan。フロントは SWR/staleTime/keepPreviousData/virtual-scroll 無し。
- **improvement**: (A) `received_at DESC, id DESC` の keyset (cursor) pagination 化、または simplePaginate で COUNT 排除。(B) `to_local_aizen` 等の generated column + btree index、または to_address gin_trgm_ops (HOT 影響評価必要)。(C) `subject_is_spam` boolean (generated) で functional index 化。(D) フロント useSWR + keepPreviousData + staleTime 30s + virtual list。(E) selfOwners() のドロップダウン集計を 60s キャッシュ化。
- **effort**: medium

---

## Low

### GET /api/v1/customers
- **summary**: 本番 58 行のため実害なし。予防的課題のみ。
- **root_cause**: index は PK のみ。TenantScope/ILIKE/Sort は Seq Scan だが行数極小で無視可。
- **improvement**: 将来規模拡大時に `(tenant_id, created_at DESC)` 複合 index、必要なら company_name に pg_trgm GIN。
- **effort**: small

### GET /api/v1/proposal-threads
- **summary**: GROUP BY → MAX(sent_at) → offset paginate 構造。bulk 配信拡大で線形劣化リスク。後段の delivery_send_histories 一括ロードもメモリ膨張要因。
- **root_cause**: delivery_campaigns に send_type+sent_at 複合 index 無し。サブクエリ全体の paginate() で GROUP BY 二重実行。20 スレッド分の全 send_histories (最大 1,100行/campaign) を一括ロードし PHP 側集計。
- **improvement**: (a) `(tenant_id, sent_at DESC) WHERE send_type IN (...)` 部分 index 追加。(b) latest_sent_at/replied_at/has_unread_reply/sent_count を delivery_campaigns に集計列で持たせ、後段 send_histories ロード廃止。(c) フロント SWR/react-query + staleTime 30s。(d) bulk スレッドは遅延ロード化。
- **effort**: medium

### GET /api/v1/tasks
- **summary**: tasks の tenant_id/user_id/due_date/status/priority に index 一切なし。現状件数では実害なし。
- **root_cause**: FK 暗黙 index 以外 index 無し。検索は title ILIKE + whereHas('customer') ILIKE で trgm GIN なし。事業特性上テーブル小規模。
- **improvement**: 将来増加時に `(tenant_id, user_id, due_date)` 複合 index、`(tenant_id, status)`/`(tenant_id, priority)` 部分 index、title/company_name の pg_trgm GIN。フロントは useSWR + keepPreviousData でページ送り体感向上。
- **effort**: small

### GET /api/v1/deals
- **summary**: search 時の whereHas('customer') ILIKE が customers に Seq Scan を打つ点と、`(tenant_id, deal_type, created_at)` 複合 index 不在が将来のボトルネック。
- **root_cause**: deals の index は PK / FK / engineer_id のみ。フロントが per_page=200 を渡すが Controller 側は未解釈。eager-load は健全。
- **improvement**: (1) `deals(tenant_id, deal_type, created_at DESC)` を CONCURRENTLY 追加。(2) customers.company_name に pg_trgm GIN。(3) フロント SWR 化と per_page 解釈の集約。
- **effort**: small

### GET /api/v1/contacts
- **summary**: 検索時 ILIKE が Seq Scan だが小規模 (<数千/テナント) で実害限定的。別件で OR 検索が括弧化されておらず tenant 分離破綻の兆候あり (性能ではなくセキュリティ)。
- **root_cause**: PK と FK auto-index のみ。pg_trgm GIN 無し。`when($request->search)` の OR 連鎖が where(function) で包まれず、TenantScope の AND と並んで OR 優先順位問題発生。
- **improvement**: (1) `when()` 内を `$q->where(function($q) {...})` で括弧化 (security fix 兼務)。(2) 必要なら contacts(name/department/position) と customers(company_name) に pg_trgm GIN。(3) 将来 1万件超で `(tenant_id, id DESC)` 検討。
- **effort**: small

### GET /api/v1/engineer-mails
- **summary**: Offset paginate + COUNT、ILIKE on engineer_mail_sources columns に trgm 無し。steady state では問題ないが search/深ページでスパイク余地。
- **root_cause**: source カラム導入後でも `(tenant_id, source, received_at DESC)` 複合 index 不在で Bitmap AND になりうる。name/nearest_station/affiliation/skills(JSON cast) に trgm なし。body_text ILIKE は ORM サブクエリ越しで Seq Scan に倒れることあり。
- **improvement**: (a) `(tenant_id, source, received_at DESC)` を CONCURRENTLY 追加。(b) 将来 keyset (received_at + id) 化で COUNT 排除。(c) name/affiliation/nearest_station の pg_trgm GIN。(d) フロント SWR / React Query。
- **effort**: small

### GET /api/v1/engineers
- **summary**: ILIKE '%...%' on name/affiliation に trgm 無し、per_page 上限なし。テナント内数百〜数千件で軽度。
- **root_cause**: engineers の btree `(tenant_id, name)` は左前方一致のみ有効で両端ワイルドカードは Seq Scan。engineer_profiles.work_style に index 無し。per_page バリデーション無し。
- **improvement**: (a) engineers.name / affiliation に pg_trgm GIN を CONCURRENTLY 追加。(b) per_page を max 100 で validate。(c) engineer_profiles(work_style) index 検討。(d) フロント SWR/React Query + staleTime。
- **effort**: small

### GET /api/v1/invoices
- **summary**: 大きな問題なし。テナント当たり数百〜数千件規模で eager-load も健全。`doc_type + issued_date DESC` を覆う複合 index 不在のみ予防課題。
- **root_cause**: 現存 index は tenant 単体・(tenant,year_month)・(tenant,customer,year_month)・(tenant,signed_scan_uploaded_at)。フロントは page= 未送信で常に 1 ページ目取得のため深掘り発生せず。
- **improvement**: 保険として `(tenant_id, doc_type, issued_date DESC, id DESC)` 複合 index。q 多用時のみ invoice_number/customer_name_snapshot の lower() expression index。フロントは simplePaginate で COUNT 省略可。
- **effort**: small

### GET /api/v1/invoice-send-histories
- **summary**: 現状件数 (月数百件規模) では実害なし。将来増加時に `(tenant_id, sent_at DESC)` 不在と q の ILIKE が顕在化。
- **root_cause**: tenant_id 単独/sent_at 単独 index のみ。doc_type フィルタが whereHas('invoice') EXISTS。q は subject/invoice 側カラム ILIKE で trgm GIN 無し。
- **improvement**: (a) `(tenant_id, sent_at DESC)` 追加。(b) doc_type を denormalize するか invoices(doc_type, id) 複合 index。(c) q 利用時に subject pg_trgm GIN。(d) 将来は sent_at+id cursor 化。
- **effort**: small

### GET /api/v1/ses-contracts
- **summary**: SES レジャー規模 (数百〜数千) では許容。trgm 無し ILIKE 三重 whereHas、deals の `(tenant_id, deal_type, deleted_at)` 不在、フロント 3 連打 (per_page=200 + summary 全件 get) が件数増加で線形悪化。
- **root_cause**: ses_contracts は `(tenant_id, contract_period_end)` のみ、deals は単体 FK index のみ。latestOfMany サブクエリが毎回実行。検索は deals.title / engineer_name / customers.company_name に対する trgm 無し ILIKE。フロントは allContracts (per_page=200) + summary (全件 get) + 本体の 3 リクエスト並列。
- **improvement**: (1) `CREATE INDEX CONCURRENTLY ON deals (tenant_id, deal_type, deleted_at) WHERE deal_type='ses' AND deleted_at IS NULL`。(2) 必要に応じ deals.title / engineer_name / company_name に pg_trgm GIN。(3) allContracts を集計エンドポイント化 or SWR + staleTime で重複排除。(4) /ses-contracts/summary を SELECT SUM/集計クエリ化。(5) keyset 化は万件超えてから。
- **effort**: small

---

## 即着手すべき Quick Wins

severity >= high かつ effort=small のみ。

- [ ] **GET /api/v1/public-projects**: `with('projectMailSource.email:id,from_address,from_name,body_text')` 追加で N+1 解消。一覧で `body_text` 不要なら formatProject 側で omit。カンバンの `per_page=200` を 50-100 に抑制。

---

## 大改修候補

severity >= high かつ effort=large。

- 該当なし (high のうち最大は `/delivery-campaigns` の **medium effort**)。
- ただし参考として、medium effort で広範な改修となるもの:
  - **GET /api/v1/delivery-campaigns** (high / medium): `(tenant_id, sent_at DESC)` / `(tenant_id, send_type, sent_at DESC)` 複合 index + delivery_send_histories.email/name の pg_trgm GIN + orderByRaw 相関サブクエリの leftJoin 化。
  - **GET /api/v1/emails** (medium / medium): keyset (cursor) pagination 化、to_address の generated column + index、subject_is_spam の functional index、フロント SWR/virtual list 化。
  - **GET /api/v1/proposal-threads** (low / medium): delivery_campaigns に latest_sent_at/replied_at/has_unread_reply の集計列追加と後段 send_histories ロード廃止。

---

## severity=none

- GET /api/v1/cards (本番 2 行のため実害なし)
- SendHistoryController (ルーティング未登録のデッドコード)