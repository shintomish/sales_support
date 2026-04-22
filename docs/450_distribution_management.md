# 配信管理 — AWS SES 本番申請・配信停止機能・バウンス処理

> 作成日: 2026-04-15 / 最終更新: 2026-04-22(バウンス処理セクション追加)

---

## 1. メール送信インフラの経緯

| 日付 | 出来事 |
|------|--------|
| 2026-04-10 | AWS SES 本番アクセス申請（初回） |
| 2026-04-11 | SES 第1回却下 → Brevo SMTP に切り替え |
| 2026-04-13 | SES 第2回申請（追加情報提供）→ 却下 |
| 2026-04-13 | Brevo → SendGrid SMTP に切り替え |
| 2026-04-15 | `aizen-sol.co.jp` の DKIM 検証済み・SPF 設定完了 |
| 2026-04-15 | AWS SES 第3回申請 → 審査中 |
| 2026-04-15 | 配信停止（unsubscribe）機能を実装・本番デプロイ |

---

## 2. 現在のメール送信構成

- **送信サービス**: SendGrid SMTP
- **送信元アドレス**: `outsource@aizen-sol.co.jp`
- **送信先**: `delivery_addresses` テーブルの `is_active = true` のアドレス
- **本番 APP_URL**: `https://app.ai-mon.net`

### DNS 設定（カゴヤ）

| 種別 | 内容 |
|------|------|
| SPF | `v=spf1 a:mss-g2-140.kagoya.net include:amazonses.com ~all` |
| DKIM | SES 用 CNAME × 3（`aizen-sol.co.jp` 検証済み） |
| DKIM | SendGrid 用 CNAME × 3（`em4827` / `s1._domainkey` / `s2._domainkey`） |

---

## 3. AWS SES 第3回申請（2026-04-15）

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

### SES 承認後の切り替え手順

`.env` の変更のみ。Brevo/SendGrid の SMTP 設定を削除し、SES 設定に戻す。

```env
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=xxxxx
AWS_SECRET_ACCESS_KEY=xxxxx
AWS_DEFAULT_REGION=ap-northeast-1
# MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD は削除
```

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

## 5. 送信量目標

| フェーズ | 送信量 |
|----------|--------|
| 初期（ウォームアップ） | 1,000 通/日 |
| 目標 | 3,000 通/日 |
| 長期スケール目標 | 36万通/月（1,200社 × 10回） |

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
- 無効化されたアドレスの手動復活が必要な場合は、一時的に `is_active=true` に戻す(配信管理画面の管理機能は未実装・現状はDB直接更新)
- AWS SES 本番移行後は SES Suppression List との連携も検討
