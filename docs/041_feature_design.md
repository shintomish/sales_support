# メール機能 機能設計書

**対象システム**: SES企業向け営業支援システム  
**作成日**: 2026-04-03  
**バージョン**: 1.0

---

## 1. 機能概要

Gmail と連携し、SES営業に関わるメール（案件情報・技術者情報）を自動的に取得・分類・抽出・登録する機能。

### 1.1 主な機能一覧

| # | 機能名 | 概要 |
|---|--------|------|
| 1 | Gmail OAuth連携 | Google OAuth2.0でGmailと接続 |
| 2 | メール同期 | Gmailから最新メールを取得・保存 |
| 3 | 自動分類 | 案件メール / 技術者メール をルールベースで振り分け |
| 4 | AI情報抽出 | Claude APIでメール本文・URLから構造化データを抽出 |
| 5 | マッチングスコア計算 | 抽出データと既存DB（案件・技術者）のマッチング度を算出 |
| 6 | マッチング候補プレビュー | 上位5件の候補を表示 |
| 7 | 技術者・案件として登録 | 抽出データをもとにDB登録 |
| 8 | 添付ファイルダウンロード | Gmail添付ファイルをダウンロード |
| 9 | 既読管理 | メール開封時に自動既読、一括既読 |
| 10 | リアルタイム受信通知 | Supabase Realtimeで新着をリアルタイム検知 |

---

## 2. システム構成

```
[Gmail API]
    ↓ OAuth2 / REST API
[GmailService]
    ↓ 取得・保存
[emails テーブル / email_attachments テーブル]
    ↓ ルールベース分類
[EmailClassificationService]
    ↓ category = project / engineer
[EmailExtractionService]
    ↓ Claude API (claude-sonnet-4-20250514)
[extracted_data.result に構造化データ保存]
    ↓ マッチング計算
[EmailMatchPreviewService]
    ↓ best_match_score / match_count 保存
[フロントエンド表示 (Next.js)]
```

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
1. Gmail API から最新50件取得（既存gmail_message_idは重複スキップ）
2. 新着メールをDBに保存（本文・添付ファイル情報含む）
3. 未分類メールを最大20件・新着順で即時分類
4. 未抽出メールを最大5件・新着順で即時抽出（Claude API呼び出し）
5. 抽出後にマッチングスコアを自動計算・保存
```

**レスポンス:**
```json
{
  "message": "3件の新着メールを取得しました",
  "count": 3,
  "classified": 3,
  "extracted": 2
}
```

---

### 4.3 自動分類（EmailClassificationService）

ルールベース（Claude API不使用）で高速分類。

**分類優先順位:**

| 優先度 | 条件 | 分類結果 |
|--------|------|----------|
| 1 | 添付ファイルあり | `engineer` |
| 2 | 件名に `【技術者情報】` | `engineer` |
| 3 | 件名に人材系キーワード（人材/人財/スキルシート/経歴書/ご紹介 等） | `engineer` |
| 4 | 件名に `【案件情報】` | `project` |
| 5 | 本文にURL含む | `project` |
| 6 | 本文のみ（URLなし） | `project` |

**スケジューラー:** 15分ごとに `classifyPending()` を実行

---

### 4.4 AI情報抽出（EmailExtractionService）

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

**スケジューラー:** 30分ごとに `extractPending(20)` を実行（新着順）

---

### 4.5 マッチングスコア計算（EmailMatchPreviewService）

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

#### 技術者登録（`POST /api/v1/emails/{id}/register-engineer`）
抽出データをもとに `engineers` + `engineer_profiles` + `engineer_skills` を作成。

#### 案件登録（`POST /api/v1/emails/{id}/register-project`）
抽出データをもとに `public_projects` + `project_required_skills` を作成。

両登録とも `emails.registered_at` にタイムスタンプを記録し、以降の再登録を防止。

---

## 5. API一覧

| メソッド | エンドポイント | 説明 |
|----------|---------------|------|
| GET | `/api/v1/gmail/status` | Gmail接続状態確認 |
| GET | `/api/v1/gmail/redirect` | OAuth認証URL取得 |
| GET | `/api/v1/gmail/callback` | OAuth コールバック |
| GET | `/api/v1/emails` | メール一覧（ページネーション） |
| POST | `/api/v1/emails/sync` | Gmail同期 |
| POST | `/api/v1/emails/mark-all-read` | 全件既読 |
| GET | `/api/v1/emails/unread-count` | 未読件数 |
| GET | `/api/v1/emails/{id}` | メール詳細（自動既読） |
| POST | `/api/v1/emails/{id}/extract` | AI抽出（手動） |
| GET | `/api/v1/emails/{id}/match-preview` | マッチング候補取得 |
| POST | `/api/v1/emails/{id}/register-engineer` | 技術者として登録 |
| POST | `/api/v1/emails/{id}/register-project` | 案件として登録 |
| PATCH | `/api/v1/emails/{id}/link` | 担当者・商談へ紐付け |
| GET | `/api/v1/emails/{id}/attachments/{attachmentId}/download` | 添付DL |
| GET | `/api/v1/project-mails` | 案件メール一覧（スコアリング済み） |
| PATCH | `/api/v1/project-mails/{id}/status` | ステータス変更（確定/除外等） |
| GET | `/api/v1/project-mails/{id}/matched-engineers` | マッチング技術者一覧 |
| POST | `/api/v1/project-mails/{id}/generate-proposal` | 提案メール草稿生成（Claude Haiku） |
| POST | `/api/v1/project-mails/rescore-all` | 全件再スコアリング |

---

## 6. スケジューラー設定

| ジョブ名 | 実行間隔/時刻 | 処理内容 | 上限 |
|----------|------------|----------|------|
| `sync-emails` | 15分ごと | Gmailメール同期 | 50件/回 |
| `classify-emails` | 15分ごと | 未分類メール分類 | 全件 |
| `extract-emails` | 30分ごと | AI情報抽出 | 20件/回 |
| `gmail:trash-classified` | 毎日2:00 | 分類済みメールをGmailゴミ箱へ移動 | 全件 |
| `emails:cleanup` | 毎日3:00 | メール本文NULL化・古いレコード削除 | 全件 |

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

- **スコア色分け**: 抽出済みメールはマッチングスコアに応じて背景色を変更（インラインスタイル）
- **カテゴリバッジ**: 案件 (青) / 技術者 (紫) / 不明 (灰)
- **未読ドット**: 未読メールに青ドット表示
- **登録済みバッジ**: 登録済みメールに緑バッジ
- **ページネーション**: 30件/ページ
- **フィルタ**: 未読のみ・案件のみ・技術者のみ・フリーワード検索

### 8.2 右ペイン（メール詳細）

- **AI自動抽出パネル**: 分類済みメールに表示
  - `Claude抽出` / `再抽出` ボタン
  - マッチング候補ボタン（スコア色: 🟢緑 / 🟡黄 / 灰）
  - 技術者として登録 / 案件として登録ボタン
- **抽出結果表示**: スキルタグ・単価・勤務地・開始日等
- **添付ファイル**: ダウンロードボタン付き一覧
- **本文表示**: HTML / プレーンテキスト切り替え

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
