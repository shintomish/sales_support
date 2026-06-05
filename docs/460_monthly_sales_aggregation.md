# 月別売上集計 機能設計メモ

**ステータス**: バックエンド／フロント実装・本番デプロイ済み (2026-06-05)
**起票日**: 2026-05-16
**起票背景**: ダッシュボード「月別売上」が `deals.updated_at` 基準で不正確。半期決算会議の月別売上集計は SES台帳ベースで行うべき。

---

## 実装サマリ (2026-06-05 / 論点1〜5 確定 + 年度ビュー)

確定設計: **契約期間ベース / 月単位粗計上 / 売上＋利益＋仕入 / Deal と並行表示 / 明細テーブル**。
表示は **会計年度（決算月で区切り）・期** 単位（テナント決算設定から算出）。

| 論点 | 決定 |
|---|---|
| 1 集計粒度 | (b) 売上(income_amount)+利益(profit)+仕入(billing_plus_29) |
| 2 月の判定 | (a) 契約期間ベース: start<=月末 AND (end>=月初 OR end NULL) |
| 3 月またぎ | (b) 月単位粗計上 (按分なし・各月に全額) |
| 4 テーブル | (B) 明細テーブル `monthly_sales_details` を真実のソース。サマリは SUM |
| 5 切替 | (b) 並行表示 (Deal ベース=見込み / SES ベース=確定) |

### 会計年度・期 (テナント決算設定)
- `tenants.fiscal_year_end_month` (決算月) と `tenants.first_period_fiscal_year` (第1期の年度) を保持。
- **年度** = 決算月で終わる会計年度を、その終了月の暦年で表記 (9月決算 → 2025/10〜2026/9 = **2026年度**)。
- **期** = 年度 − 第1期年度 + 1 (第1期=2011年度 なら 2026年度=16期)。
- 自社テナント(aizen)は 9月決算・第1期2011年度 で初期化済み。他テナントは未設定(=暦年フォールバック)。
- 設定は **請求書発行元設定 (/settings/invoice-issuer)** の「決算情報」で編集 (tenant_admin/super_admin)。
- Tenant ヘルパー: `currentFiscalYear()` / `fiscalYearMonths()` / `periodFor()`。

### バックエンド実装ファイル
- migration: `2026_06_05_123438_create_monthly_sales_details_table.php` (RLS + service_role GRANT)
- migration: `2026_06_05_131904_add_fiscal_settings_to_tenants.php`
- `app/Models/MonthlySalesDetail.php` / `app/Models/Tenant.php`(決算ヘルパー)
- `app/Services/MonthlySalesAggregationService.php` — `aggregateMonth(year,month,?tenantId)` / `aggregatePreviousMonth()` (全テナント横断・冪等 delete→insert)
- `app/Http/Controllers/Api/MonthlySalesController.php` — index(年度ビュー: fiscal_year・12ヶ月+合計+期) / details / recompute(年度まとめて or 単月)
- `app/Http/Controllers/Api/InvoiceIssuerController.php` — 決算情報の show/update
- ルート: `GET /api/v1/monthly-sales?fiscal_year=YYYY`, `GET /api/v1/monthly-sales/{year}/{month}/details`, `POST /api/v1/monthly-sales/recompute`
- ダッシュボード `/api/v1/dashboard` に `monthly_sales` (SES確定売上6ヶ月・見込みと並行) を追加
- 月初バッチ: `routes/console.php` `aggregate-monthly-sales` (毎月1日 01:00 JST・全テナント前月再集計)
- テスト: `tests/Pgsql/Feature/MonthlySalesAggregationTest.php` (重なり判定/月粗計上/cross-tenant/年度・期算出/冪等性 = 5 passed)

### フロント実装 (sales_support_next)
- `src/app/monthly-sales/page.tsx` — 年度ビュー (←→年度切替・12ヶ月+合計行・明細ドリルダウン・「この年度を再集計」)。SES台帳と同じフルハイト+sticky スクロール
- `src/app/dashboard/page.tsx` — 「確定(SES台帳)」棒グラフを「見込み(商談)」と並行表示
- `src/app/settings/invoice-issuer/page.tsx` — 「決算情報」セクション (決算月・第1期の年度・年度/期プレビュー)
- `src/components/Sidebar.tsx` — 販売管理に「月別売上」追加 (sesOnly)
- `src/lib/supabase.ts` — クライアントを遅延初期化 (Vercel Preview の env 未設定でも `next build` が落ちないよう堅牢化)

### 運用上の注意
- 本番 `monthly_sales_details` は初期空。月初バッチか「この年度を再集計」で投入される。
- **論点2(契約期間ベース)が既存 Excel 集計と一致するかは業務側ヒアリング推奨**。本番でバックフィル(過去年度の一括再集計)する前に確認。不一致なら `work_month` カラム新設 = 論点2(c) へ切替余地。

---

## 1. 背景・現状

### 現状の挙動
ダッシュボード `/api/v1/dashboard` の `monthly_revenue` は以下のロジック:

```php
// app/Http/Controllers/Api/DashboardController.php:55-66
Deal::where('status', '成約')
    ->whereMonth('updated_at', $month->month)
    ->whereYear('updated_at', $month->year)
    ->sum('amount');
```

### 現状の問題
- 専用の売上テーブルが無く、`deals` テーブルのリアルタイム集計
- 月の判定基準が **`updated_at`**（成約日カラムではない）
  - 成約後にメモ修正等で更新されると、その商談の集計月がスライドする
- 請求書 / SES契約 / billing-summaries とは未連動
- 実売上ではなく「商談見込み金額」を売上として表示している状態

### 業務側の実態
- **半期ごとに決算会議**があり、月別売上を集計している
- 経理は **入金表**（売上先サイト日昇順、仕入先支払日昇順）を別途月別管理 → 本機能とは別管轄

---

## 2. 求める仕様

### ソース
- **SES台帳** (`ses_contracts`) を集計起点とする
- 主な参照カラム:
  - `income_amount` (顧客請求額・売上)
  - `billing_plus_22` / `billing_plus_29` (技術者支払額・仕入)
  - `profit` (利益)
  - `contract_period_start` / `contract_period_end` (契約期間)
  - `payment_site` (入金サイト)
  - `vendor_payment_site` (支払サイト)

### トリガー
1. **月初 BAT** — `Schedule::call()` で前月分を自動集計（Queue ワーカー不要、CLAUDE.md の Schedule::call 方針）
2. **手動再集計ボタン** — 画面から任意月を再集計できる UI

### 出力先
- 集計結果を保存する新テーブル（仮称 `monthly_sales_summaries`）
- ダッシュボードはこの保存テーブルから読む（リアルタイム集計を廃止）

---

## 3. 設計詰めるべき論点

実装前に決定が必要な5点:

### 論点 1: 集計粒度
月別の何を保存するか:
- (a) 売上 (`SUM(income_amount)`) のみ
- (b) 売上 + 利益 (`profit`) + 仕入 (`billing_plus_22/29`) も保存
- (c) 上記 + 件数 / 顧客数 / 技術者数 などの KPI も含む

→ **推奨**: (b)。決算会議では利益も見るはず。仕入も決算PL構成上必要。

### 論点 2: 月の判定基準
ある SES案件をどの月の売上に計上するか:
- (a) **契約期間ベース**: `contract_period_start` 〜 `contract_period_end` がその月をカバーしているSES案件すべて
- (b) **入金予定月ベース**: 契約期間 + `payment_site` から逆算した入金月
- (c) **作業月カラム新設**: `ses_contracts` または別テーブルに `work_month` を持たせて明示管理

→ Excel での既存集計ロジックに合わせる必要あり。要 業務側ヒアリング。

### 論点 3: 月またぎの月割り
契約が月途中で開始/終了する場合:
- (a) **按分**: 日割り計算で月をまたぐ
- (b) **粗計上**: その月に1日でも稼働があれば全額計上、または契約開始月のみ計上
- (c) **作業月単位**: 論点2(c) と組み合わせて月を明示管理

→ Excel 慣行に依存。SES業界では月単位粗計上が一般的。

### 論点 4: 保存テーブル設計
2案:
- **粒度A: 月別合計のみ**
  ```
  monthly_sales_summaries
    (tenant_id, year, month, total_revenue, total_cost, total_profit, deal_count, ...)
  ```
- **粒度B: 月別 × SES案件 明細**
  ```
  monthly_sales_details
    (tenant_id, year, month, ses_contract_id, revenue, cost, profit)
  ```
  + ビューまたはサマリーテーブルで集計

→ **推奨**: B。後から「あの月、どの案件で売上が立ったか」をドリルダウンできる。集計値はビューまたは別サマリー。

### 論点 5: ダッシュボード切替時期
- (a) 集計テーブル運用開始と同時に Deal ベースを廃止
- (b) しばらく並行（Deal ベース = リアルタイム見込み、SES ベース = 確定売上）
- (c) Deal ベースを「見込み売上」として残し、SES ベースを「確定売上」として別グラフ追加

→ 並行期間を設ける (b) or (c) が無難。

---

## 4. Phase 3: 経理 入金表（別スコープ）

本機能とは別ロールアウトで、Phase 3 あたりで開発予定:

- **売上先入金表**: 顧客ごとに入金サイト日（例: 月末締め翌40日）順で予定/実績を月別管理
- **仕入先支払表**: 技術者・協力会社への支払サイト日順で予定/実績を月別管理
- 経理担当者の資金繰り把握用
- 本機能（月別売上集計）の `monthly_sales_details` を参照する形で連携可能性あり
- **CSV エクスポート → 弥生会計に取込**
  - 入金表/支払表を CSV 出力 → 弥生会計の仕訳インポート形式に整形
  - 弥生会計の取込フォーマット仕様は要確認（仕訳辞書 / 部門 / 科目コードのマッピングが必要）
  - 既存の Excel 手作業（経理→弥生）を置き換えるのが目的

---

## 5. 着手前のアクション

1. 業務側ヒアリング
   - 既存 Excel 集計のロジック確認（月の判定基準・月またぎ処理）
   - 決算会議で見る項目の確定（売上のみ？利益も？仕入も？）
2. 上記 論点 1〜5 の仕様決定
3. テーブル設計確定 → migration
4. 集計バッチ実装 (`Schedule::call`)
5. 手動再集計ボタン UI 実装
6. ダッシュボード差し替え（並行期間あり）

---

## 6. 関連ファイル

- `app/Http/Controllers/Api/DashboardController.php` — 現行 monthly_revenue 実装
- `app/Models/SesContract.php` — 集計ソース
- `database/migrations/2026_03_24_000002_create_ses_contracts_table.php` — カラム定義
