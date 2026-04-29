# メール機能 機能設計書

**対象システム**: SES企業向け営業支援システム  
**作成日**: 2026-04-03  
**バージョン**: 1.1
**最終更新**: 2026-04-29(emails画面のAI機能削除・スケジュール現況・送信種別 等を実装に同期)

---

## 0. 本書の位置づけ・関連ドキュメント

### 本書のカバー範囲

Gmail からメールを取り込み、分類・AI抽出し、`emails` / `email_attachments` テーブルに格納するまでの **メール入口パイプライン全体** を扱う。

```
[Gmail] ──取込──▶ emails ──分類──▶ 抽出 ──▶ project_mail_sources / engineer_mail_sources
         ↑                                              ↑
         └──── 本書(410)のカバー範囲 ───┘
                                                    ここから先は 420 のカバー範囲
```

### 関連ドキュメント

| ドキュメント | カバー範囲 |
|---|---|
| **本書 (410)** | メール取込・分類・AI抽出・emails画面の2ペインUI・クリーンアップ |
| [420_matching_requirements.md](./420_matching_requirements.md) | 案件メール判定スコアリング・技術者マッチング・3秒判断UI |
| [430_engineer_mail_draft.md](./430_engineer_mail_draft.md) | 技術者メール機能の検討メモ(設計フェーズ) |
| [530_engineer_mail_flow.md](./530_engineer_mail_flow.md) | 技術者メール画面の営業向け運用マニュアル |
| [540_project_mail_flow.md](./540_project_mail_flow.md) | 案件メール画面の営業向け運用マニュアル |

### ⚠️ マッチングスコアについて(重要)

本書 4.5 で説明する **「マッチングスコア」** と、420 の 4章で説明する **「マッチングスコア」** は、**別物** である点に注意。

| | 本書(410) 4.5 のマッチングスコア | 420 の 4章 マッチングスコア |
|---|---|---|
| 画面 | `/emails` のメール詳細プレビュー | `/matching/[id]` の3秒判断画面 |
| 目的 | 「このメールはマッチング候補があるか?」のスクリーニング | 「この案件にはどの技術者が最適か?」の本格マッチング |
| 計算要素 | スキル50%・単価30%・勤務形態20% | 必須40%・スキル25%・条件20%・稼働10%・商流5% |
| 実装クラス | `EmailMatchPreviewService` | `MatchingService` |
| トリガー | AI抽出後に自動計算 | 案件メールから手動起動 |

両者は別の実装クラスを使う別機能であり、**値の置き換えや統合はしないこと**。

---

## 1. 機能概要

Gmail と連携し、SES営業に関わるメール（案件情報・技術者情報）を自動的に取得・分類・抽出・登録する機能。

### 1.1 主な機能一覧

| # | 機能名 | 概要 |
|---|--------|------|
| 1 | Gmail OAuth連携 | Google OAuth2.0でGmailと接続 |
| 2 | メール同期 | Gmail API + KAGOYA IMAP の二系統で受信メールを取得・保存 |
| 3 | 自動分類 | 案件メール / 技術者メール をルールベースで振り分け |
| 4 | 添付ファイルダウンロード | Gmail / IMAP / Supabase Storage のフォールバック付き |
| 5 | 既読管理 | メール開封時に自動既読、一括既読 |
| 6 | リアルタイム受信通知 | Supabase Realtimeで新着をリアルタイム検知 |

> **注**: AI情報抽出・マッチングスコア・「技術者/案件として登録」ボタンは emails 画面からは削除済み（2026-04 リファクタ）。情報抽出・スコアリングは案件メール画面 (`project_mail_sources`) と技術者メール画面 (`engineer_mail_sources`) でそれぞれ専用パイプラインに置き換わった。詳細は 530 / 540 を参照。

---

## 2. システム構成

```
[Gmail API]                [KAGOYA IMAP (POP3代替)]
    ↓ OAuth2 / REST API        ↓ IMAP EXAMINE (読取専用)
[GmailService]            [KagoyaMailService]
            ↓ 取得・保存
   [emails テーブル / email_attachments テーブル]
            ↓ ルールベース分類
   [EmailClassificationService]
            ↓ category = project / engineer / other
   ┌────────────────┴────────────────┐
   ↓ project                          ↓ engineer
[ProjectMailScoringService]    [EngineerMailScoringService]
   ↓ 正規表現抽出 + スコア          ↓ 正規表現抽出 + スコア（添付Claude解析あり）
[project_mail_sources]         [engineer_mail_sources]
            ↓
   [フロントエンド表示 (Next.js)]
```

> EmailExtractionService / EmailMatchPreviewService は現行リポジトリに残るが、emails画面UIからは利用していない（過去機能）。

---

## 3. データベース設計

### 3.1 emails テーブル

| カラム | 型 | 説明 |
|--------|-----|------|
| id | bigint | PK |
| tenant_id | bigint | テナントID（マルチテナント） |
| gmail_message_id | string | Gmail メッセージID（重複防止） |
| thread_id | string | Gmail スレッドID |
| subject | string | 件名 |
| from_address | string | 差出人メールアドレス |
| from_name | string\|null | 差出人名 |
| to_address | string | 宛先メールアドレス |
| body_text | text\|null | 本文（プレーンテキスト） |
| body_html | text\|null | 本文（HTML） |
| received_at | datetime | 受信日時 |
| is_read | boolean | 既読フラグ |
| category | string(20)\|null | `project` / `engineer` / `unknown` |
| extracted_data | jsonb\|null | 分類・抽出データ（後述） |
| classified_at | datetime\|null | 分類実行日時 |
| registered_at | datetime\|null | 技術者/案件として登録した日時 |
| registered_engineer_id | bigint\|null | 登録した技術者ID |
| registered_project_id | bigint\|null | 登録した案件ID |
| best_match_score | integer\|null | マッチングスコア上位（0〜100） |
| match_count | integer | マッチング候補件数 |
| contact_id | bigint\|null | 紐付け担当者ID |
| deal_id | bigint\|null | 紐付け商談ID |
| customer_id | bigint\|null | 紐付け顧客ID |

### 3.2 email_attachments テーブル

| カラム | 型 | 説明 |
|--------|-----|------|
| id | bigint | PK |
| email_id | bigint | FK → emails.id |
| filename | string | ファイル名 |
| mime_type | string\|null | MIMEタイプ |
| size | bigint\|null | ファイルサイズ（バイト） |
| gmail_attachment_id | string | Gmail API の attachmentId |
| storage_path | string\|null | Supabase Storage 保存パス |

### 3.3 extracted_data の JSON構造

```json
{
  "classification_reason": "has_attachment",
  "urls": ["https://example.com/job/123"],
  "has_attachments": true,
  "valid_urls": ["https://example.com/job/123"],
  "source": "url",
  "extracted_at": "2026-04-03T10:00:00+09:00",
  "result": {
    // 案件メール（category=project）
    "title": "Javaバックエンド開発",
    "description": "案件概要...",
    "end_client": "大手金融会社",
    "skills": ["Java", "Spring Boot", "AWS"],
    "unit_price_min": 60,
    "unit_price_max": 75,
    "contract_type": "準委任",
    "contract_period_months": 6,
    "start_date": "2026-05-01",
    "work_location": "東京都千代田区",
    "nearest_station": "大手町",
    "work_style": "hybrid",
    "remote_frequency": "週3リモート",
    "required_experience_years": 3,
    "interview_count": 2,

    // 技術者メール（category=engineer）
    "name": "山田 太郎",
    "experience_years": 8,
    "desired_unit_price_min": 65,
    "desired_unit_price_max": 75,
    "available_from": "2026-05-01",
    "preferred_location": "東京・神奈川",
    "self_introduction": "Javaを中心としたバックエンド開発..."
  }
}
```

---

## 4. 機能詳細

### 4.1 Gmail OAuth連携

**フロー:**
1. フロントエンドから `GET /api/v1/gmail/redirect` → Google認証URLを取得
2. ユーザーがGoogleでログイン・権限許可
3. コールバック `GET /api/v1/gmail/callback?code=xxx` でトークン取得・保存
4. `gmail_tokens` テーブルにアクセストークン・リフレッシュトークンを保存

**スコープ:** `gmail.readonly`（読み取り専用）

**トークン管理:**
- アクセストークンの有効期限切れ時は自動リフレッシュ
- リフレッシュトークンが無効な場合は `token_expired: true` を返しフロントで再接続促す

---

### 4.2 メール同期（`POST /api/v1/emails/sync`）

**処理フロー:**
```
1. Gmail API から最新メールを取得（既存gmail_message_idは重複スキップ）
2. バウンス通知メール（mailer-daemon等）を除外
3. 新着メールをDBに保存（本文・添付ファイル情報含む）
4. 添付ファイルは Supabase Storage に保存
```

> 分類・スコアリングはこのAPIでは行わない（後段スケジューラに分離）。
> KAGOYA IMAP受信は `KagoyaMailService::syncEmails()` 側で15分毎に自動実行される。

**レスポンス:**
```json
{
  "message": "3件の新着メールを取得しました",
  "count": 3
}
```

---

### 4.3 自動分類（EmailClassificationService）

ルールベース（Claude API不使用）で高速分類。

**分類優先順位:**

| 優先度 | 条件 | 分類結果 |
|--------|------|----------|
| 0 | 自社ドメイン (@aizen-sol.co.jp) からのメール ※ `outsource@` は対象外 | `other` |
| 1 | 添付ファイルあり | `engineer` |
| 2 | 件名に `【技術者情報】` | `engineer` |
| 3 | 件名に人材系キーワード（人材/人財/正社員/プロパー/要員/スキルシート/経歴書/職務経歴/フリーランス/ご紹介/弊社直/弊社要員/弊社社員/直個人） | `engineer` |
| 3.5 | 件名にイニシャル＠地名パターン（例: `IY＠京王多摩センター`） | `engineer` |
| 3.6 | 件名に年齢＋単価パターン（例: `28歳／…／70万`） | `engineer` |
| 4 | 本文に技術者本文キーワード（弊社要員をご紹介・要員のご紹介・スキルシートを添付・技術者情報を送付・技術者をご紹介させて 等） | `engineer` |
| 5 | 件名に `【案件情報】` | `project` |
| 6 | 本文にURLあり | `project` |
| 7 | 本文のみ（URLなし） | `project` |

**スケジューラー:** 15分ごとに `classifyPending()` を実行

---

### 4.4 AI情報抽出（EmailExtractionService）

> ⚠️ **現状の利用状況**: emails 画面UIでは利用していない（2026-04 リファクタで削除）。サービスクラスとしては残存。実運用の抽出は `ProjectMailScoringService` / `EngineerMailScoringService` の正規表現ベースに置き換わった。本節は履歴目的で残置。

Claude API（`claude-sonnet-4-20250514`）を使用して構造化データを抽出。

**処理フロー:**
```
1. extracted_data.urls から有効URLを選別（トラッキングURL・配信管理URL等を除外）
2. 有効URLがあればWebフェッチ（User-Agent: SalesSupportBot/1.0、タイムアウト10秒）
3. 抽出ソース決定: URL取得成功 > メール本文（最大4000文字）
4. Claude APIにプロンプト送信（max_tokens: 1024）
5. JSON形式で抽出結果を受け取り extracted_data.result に保存
6. 抽出成功後、マッチングスコアを自動計算して保存
```

**URLフィルタ（除外対象）:**
- メールマガジン配信ASP（cuenote.jp, bme.jp 等）
- 海外メール配信（mailchimp.com, sendgrid.net 等）
- SNS・日程調整ツール（line.me, calendly.com 等）
- トラッキングパス（/unsubscribe, /track/click 等）
- 会社トップページ（パスなし or `/` のみ）
- ランダム文字列20文字以上のパス

**スケジューラー:** 現在は自動実行していない（emails画面UIから利用していないため）。

---

### 4.5 マッチングスコア計算（EmailMatchPreviewService）

> ⚠️ **現状の利用状況**: emails 画面UIでは利用していない（2026-04 リファクタで削除）。代替としては案件メール画面の `matched-engineers` API、技術者メール画面の `matched-projects` API を使用する。

> ⚠️ **注意**: 本セクションのスコアは「メール画面プレビュー用」の軽量スクリーニング。案件→技術者の本格マッチングとは別物。詳細は本書「0. 本書の位置づけ」参照。

抽出データと既存DBのマッチング度を0〜100点で算出。

**スコア計算式:**

```
スコア = スキル一致率 × 50% + 単価適合度 × 30% + 勤務形態一致 × 20%
```

| 要素 | 重み | 計算方法 |
|------|------|----------|
| スキル一致率 | 50% | `一致スキル数 / max(抽出スキル数, DB登録スキル数)` |
| 単価適合度 | 30% | 単価レンジが重なれば1.0、近い場合は部分点（20万円差で0点） |
| 勤務形態一致 | 20% | 完全一致=1.0、hybrid=0.6、不一致=0.0、不明=0.5 |

**スコアバッジ:**

| スコア | バッジ | 左ペイン背景色 | ボタン色 |
|--------|--------|----------------|----------|
| 70点以上 | 🟢 | 薄緑 (#bbf7d0) | 緑 (bg-green-600) |
| 45〜69点 | 🟡 | 薄黄 (#fef08a) | 黄 (bg-yellow-400) |
| 0〜44点 | なし | 薄灰 (#e5e7eb) | 灰 (bg-gray-200) |
| 未スコア | なし | なし（白） | 灰 (bg-gray-200) |

**マッチング方向:**
- 案件メール (`category=project`) → 登録済み技術者をマッチング
- 技術者メール (`category=engineer`) → 公開中案件をマッチング

---

### 4.6 マッチング候補プレビュー（`GET /api/v1/emails/{id}/match-preview`）

上位5件の候補を返す。

**レスポンス例（案件メール → 技術者マッチング）:**
```json
{
  "category": "project",
  "matches": [
    {
      "id": 12,
      "name": "山田 太郎",
      "score": 78,
      "score_badge": "🟢",
      "skill_matches": ["java", "spring boot"],
      "desired_price_min": 65,
      "desired_price_max": 75,
      "work_style": "hybrid",
      "available_from": "2026-05-01",
      "affiliation": "株式会社A"
    }
  ]
}
```

---

### 4.7 技術者・案件として登録

> ⚠️ **emails 画面の register-engineer / register-project エンドポイントは廃止済み（2026-04 リファクタ）。**
> 現行の登録動線は以下:
> - 技術者: 技術者メール画面 → `POST /api/v1/engineer-mails/{id}/register-engineer`
> - 案件: 案件メール画面 → 公開案件画面の手動入力（メール起点の自動登録は未提供）

`emails.registered_engineer_id` / `registered_project_id` / `registered_at` カラムは互換のためテーブルに残存している。

---

## 5. API一覧

### Gmail OAuth / メール基本

| メソッド | エンドポイント | 説明 |
|----------|---------------|------|
| GET | `/api/v1/gmail/status` | Gmail接続状態確認 |
| GET | `/api/v1/gmail/redirect` | OAuth認証URL取得 |
| GET | `/api/v1/gmail/callback` | OAuth コールバック |
| DELETE | `/api/v1/gmail/disconnect` | Gmail連携解除 |
| GET | `/api/v1/emails` | メール一覧（ページネーション） |
| POST | `/api/v1/emails/sync` | Gmail同期 |
| POST | `/api/v1/emails/mark-all-read` | 全件既読 |
| GET | `/api/v1/emails/unread-count` | 未読件数 |
| GET | `/api/v1/emails/{id}` | メール詳細（自動既読） |
| PATCH | `/api/v1/emails/{id}/link` | 担当者・商談・顧客へ紐付け |
| GET | `/api/v1/emails/{id}/attachments/{attachmentId}/download` | 添付DL |

### 案件メール

| メソッド | エンドポイント | 説明 |
|----------|---------------|------|
| GET | `/api/v1/project-mails` | 案件メール一覧 |
| POST | `/api/v1/project-mails/rescore-all` | 全件再スコアリング（offsetバッチ対応） |
| POST | `/api/v1/project-mails/reextract-all` | 全件再抽出（スコア保持） |
| GET | `/api/v1/project-mails/{id}` | 詳細 |
| PATCH | `/api/v1/project-mails/{id}` | 抽出情報の手動修正 |
| PATCH | `/api/v1/project-mails/{id}/status` | ステータス変更 |
| POST | `/api/v1/project-mails/{id}/rescore` | 単票再スコア |
| GET | `/api/v1/project-mails/{id}/thread` | 提案・配信のスレッド会話履歴 |
| GET | `/api/v1/project-mails/{id}/matched-engineers` | マッチング技術者一覧 |
| POST | `/api/v1/project-mails/{id}/generate-proposal` | 提案メール草稿生成 |
| POST | `/api/v1/project-mails/{id}/send-proposal` | 提案メール送信（個別） |
| POST | `/api/v1/project-mails/{id}/send-bulk` | 一斉配信 |

### 技術者メール

| メソッド | エンドポイント | 説明 |
|----------|---------------|------|
| GET | `/api/v1/engineer-mails` | 技術者メール一覧 |
| POST | `/api/v1/engineer-mails/rescore-all` | 全件再スコアリング |
| GET | `/api/v1/engineer-mails/{id}` | 詳細 |
| PUT | `/api/v1/engineer-mails/{id}` | 抽出情報の手動修正 |
| PUT | `/api/v1/engineer-mails/{id}/status` | ステータス変更 |
| POST | `/api/v1/engineer-mails/{id}/register-engineer` | Engineerマスタへワンクリック登録 |
| GET | `/api/v1/engineer-mails/{id}/thread` | スレッド会話履歴 |
| GET | `/api/v1/engineer-mails/{id}/matched-projects` | マッチ案件一覧 |
| POST | `/api/v1/engineer-mails/{id}/generate-proposal` | 提案文生成 |
| POST | `/api/v1/engineer-mails/{id}/send-proposal` | 提案メール送信 |
| POST | `/api/v1/engineer-mails/{id}/generate-comment` | 配信添付用の前向きコメント生成 |
| GET | `/api/v1/engineer-mails/{id}/attachment/{attachmentId}` | 添付DL |

### 提案スレッド・配信キャンペーン

| メソッド | エンドポイント | 説明 |
|----------|---------------|------|
| GET | `/api/v1/proposal-threads` | 提案スレッド一覧（user_id/last_sent/last_received付き） |
| GET | `/api/v1/delivery-campaigns` | 配信キャンペーン一覧 |
| POST | `/api/v1/delivery-campaigns` | 一斉配信作成 |
| GET | `/api/v1/delivery-campaigns/{id}` | 詳細 |
| GET | `/api/v1/delivery-campaigns/{id}/progress` | 進捗 |

---

## 6. スケジューラー設定

| ジョブ名 | 実行間隔/時刻 | 処理内容 | 上限 |
|----------|------------|----------|------|
| `sync-emails` | 15分ごと | Gmail API メール同期 | 全件 |
| `sync-kagoya-pop3` | 15分ごと | KAGOYA IMAP メール同期 | 全件 |
| `classify-emails` | 15分ごと | 未分類メール分類 | 全件 |
| `score-engineer-mails` | 15分ごと | 技術者メール新着取込・スコアリング | 100件/回（添付Claude解析なし） |
| `score-project-mails` | 15分ごと | 案件メール新着取込・スコアリング | 全件 |
| `trash-classified-emails` (`gmail:trash-classified`) | 毎日2:00 JST | 分類済みメールをGmailゴミ箱へ移動 | 全件 |
| `cleanup-emails` (`emails:cleanup`) | 毎日3:00 JST | メール本文NULL化・古いレコード削除 | 全件 |
| `rotate-vision-key` | 月次 | Vision API キーローテーション | — |

> AI抽出ジョブ (`extract-emails`) は廃止済み。
> 「技術者メール新着取込ボタン」「案件メール新着取込ボタン」は2026-04 に廃止し、15分毎の自動スケジューラに統一。

**実行基盤:** Docker コンテナ内 cron（`service cron start`）+ ホストVPS cron（`docker exec ... schedule:run`）の二重構成

---

## 7. メール保持ポリシー（2026-04-05 策定・実装済み）

Supabase Proプランのストレージ上限（8GB）対策として以下を適用。

| 対象 | 経過 | 処理 |
|------|------|------|
| 分類済み（`classified_at` あり） | 30日後 | `body_text` / `body_html` を NULL化（メタデータ・件名・送信元は残す） |
| 分類済み（`classified_at` あり） | 90日後 | レコードごと削除 |
| 未分類（`classified_at` なし） | 14日後 | レコードごと削除（処理漏れとみなす） |

**履歴の保存先**: `project_mail_sources` テーブルに「誰から・どんな案件が来たか」のメタ情報が永続保存されるため、`emails` テーブル削除後も案件履歴は参照可能。

---

## 8. フロントエンド仕様

### 8.1 左ペイン（メール一覧）

- **カテゴリバッジ**: 案件 (青) / 技術者 (紫) / 不明 (灰)
- **未読ドット**: 未読メールに青ドット表示
- **登録済みバッジ**: `registered_at` ありに緑バッジ（旧仕様の互換表示）
- **添付クリップ表示**: `attachments_count > 0` で 📎 表示
- **ページネーション**: 30件/ページ（`per_page` で変更可）
- **フィルタ**: 未読のみ・案件・技術者・フリーワード検索（差出人/件名、`本文も検索` チェックで本文も対象）

### 8.2 右ペイン（メール詳細）

- メタ情報（件名・差出人・宛先・受信日時・カテゴリバッジ）
- 紐付け情報（顧客 / 担当者 / 商談）
- 添付ファイル一覧（DLボタン付き・Storage / IMAP / Gmail のフォールバック）
- 本文（HTMLは iframe サンドボックス、無ければプレーンテキスト）

> 旧仕様にあった「Claude抽出 / 再抽出 / マッチング候補 / 技術者として登録 / 案件として登録」ボタンは emails 画面からは削除済み。情報抽出・スコアリング・登録は案件メール画面 / 技術者メール画面で行う。

### 8.3 リアルタイム更新

Supabase Realtime で `emails` テーブルの INSERT を購読。  
ページ1表示中は自動更新、それ以外は「+N 新着」バッジを表示。

---

## 9. セキュリティ・制約事項

- Gmailアクセスは **読み取り専用**（`gmail.readonly` スコープ）
- テナント分離は `GlobalScope`（`tenant_id` による行レベル制御）
- Gmail トークンはDBに暗号化せず保存（今後の課題）
- Claude API タイムアウト: 30秒
- URL フェッチタイムアウト: 10秒
- 抽出テキスト上限: 4,000文字
