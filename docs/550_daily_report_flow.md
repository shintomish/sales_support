# 日次レポート配信 フロー説明書

> 対象読者：当社営業担当者・経理担当者・管理者  
> 関連画面：`/settings/report-recipients`（配信先設定）  
> 配信機能リリース日：2026-05-05  
> **2026-05-19 大幅改良**: 「新着技術者／新着案件」を **「有効と思われるメールリスト」** に置換（前日受信メール × 過去3日マッチ上位3件のカード形式）

---

## 1. 概要

毎朝 **08:30（JST）** に「昨日の動き＋今日の要対応リスト」をメール配信する自動レポートです。

- 取得対象期間: **前日の 24時間（00:00〜24:00）**
- 配信経路: **AWS SES**（東京リージョン・本番承認済み）
- 配信先: `report_recipients` テーブルに登録された有効なメールアドレス
- 件名: `【日次レポート】YYYY-MM-DD / 要対応 N件`
- 形式: HTML（テキスト併用のマルチパート）

> 配信先が未登録のテナントには送信されません（スキップログのみ）。

---

## 2. レポートに含まれる項目

レポートは **6セクション構成** です。  
0件のセクションはレポートから除外されます（品質ゲート）。

### 2.1 要対応合計

ヘッダ最上部に「要対応合計: N件」を表示。  
`有効と思われるメールリスト（案件・技術者の親メール件数） + 期限切れ間近のSES契約` の合計件数。

### 2.2 🤖 今日のアクション（AI 提案）

要対応合計が 1 件以上のときのみ表示。  
**Claude Haiku** がレポート内容を読み取って「今日確認すべきトピックを優先順位順に最大3つ」を生成します。

#### AI 提案の特徴
- **状況サマリに登場した固有名（案件名・技術者名・契約名）のみを引用**
- システム内に確認できないリソースの推奨は禁止（プロンプトで制約）
- 「マッチングを推進」のような実行アドバイスではなく「期日・件数・対象名の確認」レベルに留める
- 期限切れ間近のSES契約があれば最優先

#### 例
```
1. 川原耕造（株式会社Uniquery）の契約期限（2026-05-31、残り26日）の更新・終了予定を確認。
2. モニターレート（ウォービース有限会社）の契約期限（2026-05-31、残り26日）の更新・終了予定を確認。
3. ウィンヴォルブ株式会社案件（スコア90、運用保守）の対応状況と配置予定を確認。
```

### 2.3 📬 受信メール（合計）

前日 24h に受信したメール件数を分類別に表示。

| 分類 | 説明 |
|---|---|
| 技術者紹介 | `emails.category = 'engineer'`（人材紹介メール） |
| 案件紹介 | `emails.category = 'project'`（案件紹介メール） |
| その他 | `emails.category = 'other'`（自社ドメイン通知など） |

### 2.4 📨 有効と思われるメールリスト（案件）

**2026-05-19 改良:** 前日受信した案件メールの上位5件について、それぞれに鮮度マッチング (FreshMailMatchingService) で抽出した **技術者メール上位3件** をぶら下げて表示します。

- **親メール条件**: 前日 24h に受信した PMS のうち quality score >= 70（重複排除後 上位5件）
- **マッチ条件**: 過去3日 / スコア70+（高） / 上位3件
- マッチ0件の親はスキップして次候補に繰上げ
- 親0件の場合も見出しと「該当なし」を表示
- 各親カードに「マッチング画面を開く →」リンク（`/matching/{id}`）→ そのまま提案送信フローに進める

> 見出し例: 「📨 有効と思われるメールリスト（案件）5 件　条件：過去3日 スコア70+」

### 2.5 👤 有効と思われるメールリスト（技術者）

**2026-05-19 改良:** 案件側と対称構造。前日受信した技術者メールの上位5名について、それぞれに **案件メール上位3件** をぶら下げて表示します。

- **親メール条件**: 前日 24h に受信した EMS のうち quality score >= 70（重複排除後 上位5件）
- **マッチ条件**: 過去3日 / スコア70+（高） / 上位3件
- 各親カードに「マッチング画面を開く →」リンク（`/engineer-mails/{id}`）

> 見出し例: 「👤 有効と思われるメールリスト（技術者）5 件　条件：過去3日 スコア70+」

**関連**: 鮮度マッチング機能本体の使い方は **docs/470_fresh_mail_matching.md §10** を参照。

### 2.6 📤 提案メール送信（合計）

前日 24h の提案メール送信実績（`delivery_send_histories`）。

| 表示項目 | 内容 |
|---|---|
| 送信成功 | `status = 'sent'` |
| 送信失敗 | `status = 'failed'` |
| 返信受信 | `status = 'replied'` |

> 送信失敗が 1件以上の場合は赤色で強調表示。

### 2.7 🟡 期限切れ間近のSES契約（30日以内）

`ses_contracts.contract_period_end` が **今後30日以内** のSES契約（最大20件）。  
残日数の昇順で表示。

| 残日数 | 表示色 |
|---|---|
| 15日以内 | **赤色（緊急）** |
| 16〜30日 | オレンジ色 |

---

## 3. 配信先の管理（`/settings/report-recipients`）

サイドバー「設定」グループから「📊 日次レポート配信先」を選択。

### 3.1 操作（CRUD）

| 操作 | 内容 |
|---|---|
| **追加** | 「+ 追加」ボタンでメールアドレス・表示名を登録 |
| **編集** | 行ごとの「編集」ボタンでメール・表示名をインライン編集 |
| **配信停止 / 再開** | 「停止」「再開」ボタンで `is_active` をトグル |
| **削除** | 「削除」ボタンで物理削除 |

### 3.2 状態表示

| 状態 | バッジ |
|---|---|
| 配信中 | 🟢 緑色「配信中」 |
| 停止中 | 🔴 赤色「停止中」 |

### 3.3 アクセス権限

- **閲覧**: 全ロール
- **追加・編集・削除・配信停止**: `super_admin` または `tenant_admin` のみ

### 3.4 同一メールアドレスの重複防止

`(tenant_id, email, report_type)` で UNIQUE 制約があります。同じメールアドレスを 2回追加することはできません。

---

## 4. 手動実行（管理者向け）

定時配信を待たずに、その時点のレポートを手動で生成・送信できます。

### 4.1 設定済み配信先全員に即時送信
```bash
ssh root@v133-18-42-139.vir.kagoya.net
docker exec sales_support_app php artisan report:daily-delivery-report
```

### 4.2 特定テナントのみ
```bash
docker exec sales_support_app php artisan report:daily-delivery-report --tenant=1
```

### 4.3 ドライラン（送信せずデータ確認）
```bash
docker exec sales_support_app php artisan report:daily-delivery-report --dry-run
```

出力例：
```
tenant_id=1: 受信者 1名 / 要対応 5件 / AIサマリ あり
  → sent: shintomi.sh@gmail.com
```

### 4.4 配信先設定を無視して特定アドレスに送信（テスト用）
```bash
docker exec --user www-data sales_support_app php artisan report:daily-delivery-report --tenant=1 --to=test@example.com
```

`report_recipients` テーブルの内容に関わらず指定アドレスに送信します。新フォーマットの確認・関係者へのプレビュー送信用。

---

## 5. 内部処理フロー

```
Laravel Scheduler (毎朝 08:30 JST、cron)
  ↓
SendDailyDeliveryReport::handle()
  ↓ 全テナントをループ（is_active=true）
  ├─ ReportRecipient で配信先を取得（report_type=daily_delivery_report, is_active=true）
  ├─ DailyReportBuilder::build($tenantId)
  │   ├─ [1] 受信メール件数集計（emails）
  │   ├─ [2] 有効と思われるメールリスト（案件）
  │   │     - 前日PMS上位5件 × FreshMailMatchingService::freshEngineerMails(過去3日, 上位3件, スコア70+)
  │   ├─ [3] 有効と思われるメールリスト（技術者）
  │   │     - 前日EMS上位5件 × FreshMailMatchingService::freshProjectMails(過去3日, 上位3件, スコア70+)
  │   ├─ [4] 提案メール送信実績（delivery_send_histories）
  │   ├─ [5] 期限切れSES契約（ses_contracts, 30日以内）
  │   ├─ 品質ゲート: 0件セクション除外（[2][3] は0件でも常に表示）
  │   └─ Claude Haiku で「今日のアクション」3つ生成（要対応 >= 1件のとき）
  ↓
DailyReport Mailable で HTML + テキストを組立
  ↓
Mail::to($email)->send() で AWS SES 経由配信（成功/失敗を逐次ログ）
```

---

## 6. データソース対応表

| レポート項目 | テーブル | 主要カラム |
|---|---|---|
| 受信メール件数 | `emails` | `category`, `received_at` |
| 有効と思われるメールリスト（案件） | `project_mail_sources` × `engineer_mail_sources` | 親PMS: `score`, `title`, `customer_name`, `required_skills`, `created_at` / 子EMS: FreshMailMatchingService 経由でスコアリング |
| 有効と思われるメールリスト（技術者） | `engineer_mail_sources` × `project_mail_sources` | 親EMS: `score`, `name`, `affiliation`, `skills`, `created_at` / 子PMS: FreshMailMatchingService 経由でスコアリング |
| 提案メール送信実績 | `delivery_send_histories` | `status`, `created_at` |
| 期限切れSES契約 | `ses_contracts` | `contract_period_end`, `engineer_name`, `deal_id` → `deals.title` / `customers.company_name` |
| 配信先 | `report_recipients` | `email`, `name`, `report_type`, `is_active` |

---

## 7. よくある質問

### Q. メールが届かない
A. 以下を順に確認してください：
1. `/settings/report-recipients` で当該アドレスが「**配信中**」になっているか
2. 迷惑メールフォルダに振り分けられていないか（送信元: `outsource@aizen-sol.co.jp` を許可リスト追加）
3. 当日の受信件数・有効と思われるメールリスト・期限切れ契約が **すべて 0 件** ではないか（その場合「本日特筆すべき動きはありませんでした」のみ表示）
4. 管理者に依頼して `php artisan report:daily-delivery-report --dry-run` でデータが取得できるか確認

### Q. AI 提案が表示されない
A. 要対応（有効と思われるメールリスト＋期限切れ契約）が **0件** の場合、AI 提案は生成されません。

### Q. 「（名前未取得）」と表示される
A. 技術者メール本文から名前を抽出できなかったケース。`EngineerMailScoringService::extractName` の対応パターンに当てはまらない特殊フォーマットです。  
頻発する場合は別途分析・パターン追加で対応します。

### Q. 配信先設定はテナント単位か、ユーザー単位か
A. **テナント単位**で管理（`report_recipients.tenant_id`）。同じテナント内のユーザー全員が同じ配信先リストを共有します。

### Q. 配信時刻を変更したい
A. `routes/console.php` の `Schedule::command('report:daily-delivery-report')->dailyAt('08:30')` を変更（要デプロイ）。

### Q. レポート種別を増やしたい（週次・アラート等）
A. `report_recipients.report_type` カラムで切り分け可能（現状は `daily_delivery_report` のみ）。  
将来的に `weekly` `alert` 等を追加する設計になっており、配信先テーブルは流用できます。新しい Mailable ＋ Builder ＋ コマンド ＋ Scheduler を追加する形になります。

---

## 8. 注意事項・既知の制限

- **配信時刻のずれ**: Laravel Scheduler は最低1分の精度。サーバー負荷状況により数秒程度ずれる可能性があります。
- **タイムゾーン**: すべて Asia/Tokyo（JST）基準で計算。海外テナントは未対応。
- **AI 提案の精度**: Claude Haiku は事実ベースの確認トピックを返しますが、まれに状況サマリに無い情報を含む場合があります（プロンプトで制約していますが完全ではありません）。
- **配信失敗のリトライなし**: 当日のみ送信。失敗時はログに記録されますが翌日まで再送はしません。緊急対応が必要な場合は手動実行で対応。
- **テナント単位のスコア閾値**: 現在は全テナント共通で 70 (`DailyReportBuilder::SCORE_THRESHOLD`)。テナント別調整は未対応。
- **マッチ探索期間**: 現在は固定 3 日 (`DailyReportBuilder::EFFECTIVE_FRESH_DAYS`)。日次配信のため繰り返し露出を抑える方針。延長したい場合は定数を変更（再デプロイ）。
- **親メール件数 / マッチ件数**: それぞれ 5 / 3 件で固定 (`EFFECTIVE_PARENTS` / `EFFECTIVE_MATCHES`)。

---

## 9. 関連ドキュメント

| 番号 | タイトル | 用途 |
|---|---|---|
| 410 | メール機能 機能設計書 | 分類・スコアリング全体仕様 |
| 530 | 技術者メール フロー | engineer_mail_sources 詳細 |
| 540 | 案件メール フロー | project_mail_sources 詳細 |
| 450 | 配信管理・AWS SES | SES 送信枠・本番運用 |

---

*作成: 2026-05-06 / 初版*  
*更新: 2026-05-19 / 「有効と思われるメールリスト」セクション追加・データ構造改良*
