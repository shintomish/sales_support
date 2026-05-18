# 配信管理 — AWS SES 本番運用・配信停止機能・バウンス処理

> 作成日: 2026-04-15 / 最終更新: 2026-05-13（監査ログ audit チャネル分離・JST ログローテーションを追記）

---

## 1. メール送信インフラの経緯

| 日付 | 出来事 |
|------|--------|
| 2026-04-10 | AWS SES 本番アクセス申請（初回） |
| 2026-04-11 | SES 第1回却下 → Brevo SMTP に切り替え |
| 2026-04-13 | SES 第2回申請（追加情報提供）→ 却下 |
| 2026-04-13 | Brevo → SendGrid SMTP に切り替え |
| 2026-04-15 | `aizen-sol.co.jp` の DKIM 検証済み・SPF 設定完了 |
| 2026-04-15 | AWS SES 第3回申請 |
| 2026-04-15 | 配信停止（unsubscribe）機能を実装・本番デプロイ |
| **2026-04-17** | **AWS SES 本番アクセス承認**（ap-northeast-1 / 日次12,000通・14通/秒） |
| 2026-04-17 | SendGrid SMTP → AWS SES に切り替え・本番運用開始 |
| 2026-04-22 | バウンス自動処理を実装 |
| 2026-05-08 | 配信停止リンクに **確認ダイアログ** を追加・停止理由 (`unsubscribe_reason`) を `recipient_unsubscribed` / `operator_disabled` の 2 種にリネーム・添付ファイル名 `unknown` をバックフィル・**再送信機能**（行単位 / キャンペーン単位）を実装 |
| 2026-05-09 | キャンペーン詳細画面のヘッダー固定・200 件ページネーション・名前列省略表示 |

---

## 2. 現在のメール送信構成

- **送信サービス**: AWS SES（ap-northeast-1 / 本番承認済 2026-04-17）
- **送信元アドレス**: `outsource@aizen-sol.co.jp`
- **送信先**: `delivery_addresses` テーブルの `is_active = true` のアドレス
- **本番 APP_URL**: `https://app.ai-mon.net`
- **送信枠**: 日次 12,000通 / 月次 240,000通 / 14通/秒（コード上は安全マージン込みで 100ms 間隔 ≒ 10通/秒）

### DNS 設定（カゴヤ）

| 種別 | 内容 |
|------|------|
| SPF | `v=spf1 a:mss-g2-140.kagoya.net include:amazonses.com ~all` |
| DKIM | SES 用 CNAME × 3（`aizen-sol.co.jp` 検証済み） |

> SendGrid 用 DKIM CNAME（`em4827` / `s1._domainkey` / `s2._domainkey`）は移行完了後、不要となったが
> 履歴として残置（削除可）。

---

## 3. AWS SES 第3回申請（2026-04-15）→ 承認（2026-04-17）

> 2026-04-17 に **本番アクセス承認**。以下は申請時の記録（履歴）。

### 申請フォーム設定

| 項目 | 設定値 |
|------|--------|
| メールタイプ | トランザクション |
| ウェブサイト URL | `https://aizen-sol.co.jp` |
| 連絡する際の希望言語 | Japanese |

### AWS から追加情報を求められた場合の返信文

```
Dear Amazon Web Services Trust & Safety Team,

Thank you for reviewing our request. Please find the details below.

**About our company:**
Aizen.Solution Co., Ltd. (https://aizen-sol.co.jp) is a Japanese IT
company providing a range of technology services including:

1. IT Infrastructure Construction
2. System Development
3. Medical System Development
4. IT Outsourcing (SES: System Engineering Service)
5. IT Education and Training

We specialize in matching skilled IT engineers with client companies
that require technical staff for their projects (SES model).

**Our email use case:**
We operate an internal sales support system that sends engineer profile
proposals to procurement managers and HR personnel at Japanese IT companies.

- Email type: Transactional / B2B sales communication
- Recipients: Corporate contacts at IT companies — procurement managers,
  HR managers, and project managers who manage outsourcing of engineers
- Content: Engineer profile summaries matched to their currently open
  positions (job title, skills, availability, rate)
- Sender address: outsource@aizen-sol.co.jp

**How we collect recipient email addresses:**
All recipient addresses are collected through legitimate B2B channels:
- Business card exchanges at in-person meetings and industry events
- Inquiry forms submitted by companies actively seeking engineers
- Publicly listed business contact information on corporate websites

These are professional business contacts who engage in commercial
procurement of IT engineers as part of their regular business operations.

**Sending frequency:**
- Emails are sent during business hours (weekdays, Japan Standard Time)
- Initial: 1,000 emails/day (for warmup and deliverability monitoring)
- Target after warmup: ~3,000 emails/day
- We will monitor bounce and complaint rates carefully before
  requesting any increase.

**Recipient list maintenance:**
- Hard bounces are automatically added to the SES Suppression List
  via SNS notifications and never contacted again
- Opt-out requests are honored immediately and added to suppression list
- We do not purchase or rent email lists

**Bounce, complaint, and opt-out handling:**
- Bounce handling: Automatic suppression via SES Suppression List
  triggered by SNS notifications
- Complaint handling: SNS notifications → immediate suppression
- Opt-out: Every email includes an unsubscribe link; requests are
  honored immediately and added to suppression list

**Sample email content:**
Subject: 【エンジニアご紹介】Java/Spring Boot 7年 即稼働可能

Body:
---
株式会社〇〇 ご担当者様

いつもお世話になっております。
株式会社アイゼン・ソリューションの新冨と申します。

この度、貴社のご要件に合致するエンジニアをご紹介させていただきます。

【スキル】Java, Spring Boot, AWS（7年）
【稼働可能時期】即日
【希望単価】60万円/月
【在籍】フリーランス

ご興味がございましたら、詳細な経歴書をお送りいたします。
ご検討のほどよろしくお願いいたします。

配信停止をご希望の場合は、こちら [unsubscribe link] からお手続きください。

株式会社アイゼン・ソリューション
新冨 泰明
outsource@aizen-sol.co.jp
https://aizen-sol.co.jp
---

**Domain verification:**
- Domain aizen-sol.co.jp is fully verified in Amazon SES
  (Asia Pacific Tokyo region)
- DKIM setup is complete and confirmed

**Compliance:**
- SPF record configured: v=spf1 include:amazonses.com ~all
- Compliant with Japan's Act on Regulation of Transmission of
  Specified Electronic Mail Act

We are committed to maintaining high deliverability standards and
are happy to provide any additional information needed.

Sincerely,
Yasuaki Shintomi
Aizen.Solution Co., Ltd.
outsource@aizen-sol.co.jp
https://aizen-sol.co.jp
```

### SES 承認後の切り替え手順（2026-04-17 実施済み）

`.env` の変更のみ。SendGrid の SMTP 設定を削除し、SES 設定に切替。

```env
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=xxxxx
AWS_SECRET_ACCESS_KEY=xxxxx
AWS_DEFAULT_REGION=ap-northeast-1
# MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD は削除
```

`config/services.php` の `ses` ブロックで上記 env を参照する。Laravel の `mail` ドライバは `ses` を選択。

---

## 4. 配信停止（Unsubscribe）機能

### 概要

一括配信メールの末尾に配信停止リンクを自動挿入。受信者がリンクをクリックすると `delivery_addresses.is_active = false` になり、以降の配信から自動除外される。

### 実装ファイル

| ファイル | 内容 |
|----------|------|
| `database/migrations/2026_04_15_192112_add_unsubscribe_token_to_delivery_addresses_table.php` | `unsubscribe_token`（UUID）カラム追加・既存レコードへの自動付与 |
| `app/Models/DeliveryAddress.php` | `boot()` で新規作成時にトークン自動生成 |
| `app/Http/Controllers/UnsubscribeController.php` | トークン検証・`is_active = false` 更新 |
| `resources/views/unsubscribe.blade.php` | 配信停止完了ページ（成功/既停止済み/無効の3パターン） |
| `routes/web.php` | `GET /unsubscribe/{token}`（認証不要） |
| `app/Services/DeliveryCampaignService.php` | 送信時に本文末尾へリンクを自動追加 |

### 配信停止 URL

```
https://app.ai-mon.net/unsubscribe/{unsubscribe_token}
```

### メール末尾への挿入形式

```
---
配信停止をご希望の場合は、こちらからお手続きください。
https://app.ai-mon.net/unsubscribe/{token}
```

### トークン付与タイミング

- **新規登録時**: `DeliveryAddress::creating()` で自動生成
- **CSVインポート時**: 同上（モデル経由のため自動）
- **既存レコード**: 2026-04-15 のマイグレーションで全件付与済み

---

## 5. 送信量目標と SES 枠

| 項目 | 値 |
|------|----|
| AWS SES 本番承認枠 | 日次 12,000通 / 月次 240,000通 / 14通/秒（2026-04-17 承認） |
| 送信レート（実装） | 100ms 間隔（≒10通/秒、SES の 14通/秒に対し安全マージン込み） |
| 当面の目標 | 日次 12,000通・月次 240,000通（一括配信） |
| 長期スケール目標 | 36万通/月（必要時に SES 枠の追加申請） |

---

## 6. バウンス(不達)処理(2026-04-22 追記)

### 概要

配信メールの不達(バウンス)を検知し、`delivery_addresses.is_active = false` に自動更新する。無効化されたアドレスは以降の配信から自動除外される。

### 検出ロジック

postmaster(`mailer-daemon`)等からのバウンスメールをGmail側で受信し、以下の分類で処理:

| 不達理由 | 判別方法 | 対応 |
|---|---|---|
| アドレス不存在 | 「存在しないアドレス」「User unknown」等 | is_active=false 自動更新 |
| 受信拒否 | 「拒否されました」「blocked」「policy reject」等 | is_active=false 自動更新 |
| メールボックス容量超過 | 「Quota exceeded」「mailbox full」等 | is_active=false 自動更新 |
| SPF/DKIM 認証失敗 | Postmaster からの認証失敗通知 | 他アカウント由来の場合は無視 |

### 実績(2026-04-15〜04-22 の1週間)

- 検出総数: 38件(重複排除済み)
- 自動無効化: 35件
- 対応不要(他アカウント由来): 3件

詳細な不達ログは [610_undelivered_list.md](../docs/610_undelivered_list.md) で週次管理する。

### 運用ルール

- 不達検出時は配信先マスタを自動で `is_active=false` に更新
- 無効化されたアドレスの手動復活が必要な場合は、配信管理画面の **配信先一覧タブ** で個別 or 一括有効化可能（一括有効/無効・スナップショット保存/復元 2026-04-26 実装）
- AWS SES 本番運用中（2026-04-17〜）。SES Suppression List との連携は今後検討

---

## 7. 監査ログ運用（2026-05-13 追記）

配信操作（キャンペーン作成・送信・再送信・配信停止）は `LogUserActivity` ミドルウェアにより **監査ログ専用チャネル** に記録されます。

| 項目 | 内容 |
|---|---|
| 出力先 | `storage/logs/audit.log`（アプリログ `sales_sup-YYYY-MM-DD.log` とは別ファイル） |
| レベル | 常に `info`（`LOG_LEVEL=error` の本番でも記録される） |
| ローテーション | JST 基準（`JstDailyFactory` / `JstRotatingFileHandler`） |
| 記録内容 | ユーザー ID・テナント ID・HTTP メソッド・パス・IP・User-Agent・タイムスタンプ |

詳細は `config/logging.php` の `audit` チャネル定義および `app/Http/Middleware/LogUserActivity.php` を参照。

---

## 8. 元請けドメイン重複警告 + 除外送信（2026-05-18 追加）

### 概要

案件メールを **元請け企業 (案件発信元) に送り返すと「抜き額」が露呈する**事故を防ぐためのガード。新規一斉配信、matching/[id] のまとめて提案、engineer-mails/[id] のまとめて提案 BP宛て、いずれのフローでも同じ思想で警告する。

### API: `/api/v1/delivery-campaigns/check-duplicates`

新規配信送信ボタン押下時、フロントが POST する。

**Request**:
```json
{
  "project_mail_id": 24413,            // または
  "engineer_mail_source_id": 12345,    // または
  "source_email": "sales@example.com"  // 手動入力
}
```

**Response**:
```json
{
  "source_email": "sales@tektek.co.jp",
  "source_domain": "tektek.co.jp",
  "matches": [
    {"id": 856, "email": "sales@tektek.co.jp", "name": "株式会社てくてく"}
  ]
}
```

**仕様**:
- `is_active=true` の `delivery_addresses` のみ対象（送信されない inactive は対象外）
- 同一ドメイン (`LOWER(email) LIKE '%@<source_domain>'`) で OR マッチ
- 最大 20 件返却

### 除外送信: `exclude_address_ids`

警告モーダルの「今回これら N 件を除外して配信」(既定 ON) を選択した場合、`POST /api/v1/delivery-campaigns` に `exclude_address_ids[]` を追加で送信:

```http
POST /api/v1/delivery-campaigns
Content-Type: multipart/form-data

subject=...
body=...
project_mail_id=24413
exclude_address_ids[]=856
exclude_address_ids[]=999
attachments[]=...
```

**バックエンド挙動** (`DeliveryCampaignService`):
- `createCampaign`: `total_count` 集計時に `whereNotIn('id', $excludeIds)` を適用
- `sendCampaign`: 宛先取得時に同じ条件を適用
- `is_active` は**変更しない** → 次回送信時は通常通り含まれる

**設計上の利点**:
- 「is_active を一時的に false にして送信後 true に戻す」方式と比較し、**送信失敗・プロセス死亡で false のまま残るリスクが無い**
- atomic (DB 状態を変更しない)
- 監査ログ的にも「この campaign の宛先集合」が単一クエリで再現可能

### 適用範囲 (本日時点)

| 画面 | 警告発火条件 | 除外送信 |
|---|---|---|
| `/deliveries?tab=send` (新規配信) | 入手元と active 配信先の同一ドメイン一致 | ✓ (`exclude_address_ids[]`) |
| `/deliveries/campaigns/[id]` 再送信 | 配信先個別と `campaign.source_domain` 一致 | (該当行のみ送信のため不要) |
| `matching/[id]` まとめて提案 | `to` 編集 && `initialTo` (案件元) と同一ドメイン | (1 宛先 modal のため不要) |
| `engineer-mails/[id]` まとめて提案 BP宛て | `to` 編集 && `initialTo` (技術者紹介元 BP) と同一ドメイン | (同上) |

### 関連実装

- フロント共通 util: `src/lib/mailDomain.ts` (`extractDomain` / `isSameDomain`)
- バック controller: `app/Http/Controllers/Api/DeliveryCampaignController.php::checkDuplicates`
- バックサービス: `app/Services/DeliveryCampaignService.php` (`createCampaign` / `sendCampaign` が `$excludeIds` を受領)
- 主要 commit: `89b47b2` (API) / `d8c21a8` (新規配信フロント) / `736c2b2` (まとめて提案フロント) / `82ad98d` + `716c4a1` (除外送信)
