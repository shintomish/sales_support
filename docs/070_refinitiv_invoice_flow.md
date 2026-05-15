# Refinitiv (LSEG) 注文書 PDF → 請求書ドラフト発行フロー

> 作成日: 2026-05-13 / 対象: SES 担当・経理担当・開発担当

---

## 0. 本書の位置づけ

Refinitiv（リフィニティブ・ジャパン株式会社）は SAP Business Network 経由で **PDF の注文書** を送ってくる。注文書の「その他の情報」欄に申請者・申請番号・Plant.ID・分類コード等の必須項目があり、これを請求書へもれなく転記する必要がある。

本書は、その PDF を Claude API で構造化抽出し、対象 SES契約に紐付けて **英文モードの請求書ドラフト** を発行するまでの専用フローを記載する。

| 関連ドキュメント | カバー範囲 |
|---|---|
| [`500_users_manual.md` §5.3](500_users_manual.md) | 営業/経理担当向けの画面操作（請求書作成画面） |
| [`500_users_manual.md` §10.2](500_users_manual.md) | 発行元設定の英文情報セクション |
| 本書 (070) | バックエンド処理・抽出フィールド・DB スキーマ・API |

---

## 1. ビジネスフロー

```
Refinitiv (SAP Business Network) ──PDF送付──▶ 経理担当
                                                ↓ /billing-summaries
                                       「Refinitiv注文書から請求書発行」モーダル
                                                ↓ PDF アップロード
                              POST /api/v1/invoices/refinitiv/parse
                                                ↓ Claude Sonnet 4 で構造化
                                       抽出結果を画面で確認・編集
                                                ↓ SES契約・年月を選択
                              POST /api/v1/invoices/refinitiv/issue
                                                ↓
                                  英文モードの請求書ドラフト (draft)
                                                ↓ 通常フロー
                                  PDF 生成 → 承認 → メール送信
```

---

## 2. 画面操作（経理担当向け）

1. `/billing-summaries` を開く
2. 右上のオレンジボタン **「📋 Refinitiv注文書から請求書発行」** をクリック
3. モーダル内に Refinitiv が送ってきた PDF をドラッグ＆ドロップ（最大 10MB / PDF のみ）
4. 抽出が完了すると、フォームに以下のフィールドが自動入力される（編集可）
   - **PO Number**（注文書番号）— `invoices.order_number` に保存
   - **Description**（品名行・例: `Aizen - JBIC - Market data consulting Apr-Jun2026`）— 請求書の件名に転用
   - **Requested Delivery Date** / **Amount Based Receipt** / **Purchase Request Line** / **Requester** / **Request Number** / **Plant ID** / **Plant Name** / **TR Plant ID** / **Ship To Address Name** / **Classification Domain** / **Classification Code**
5. 紐付け先の **SES契約**（既定でリフィニティブ・ジャパンの契約のみ表示）と **対象年月** を選択
6. 「請求書ドラフトを作成」をクリック → ドラフト請求書が作成され、編集画面に遷移

---

## 3. バックエンド構成

### 3.1 主要ファイル

| ファイル | 役割 |
|---|---|
| `app/Http/Controllers/Api/RefinitivInvoiceController.php` | API エンドポイント（parse / issue） |
| `app/Services/RefinitivPoParserService.php` | PDF→Claude API 呼び出しによる構造化 |
| `app/Services/InvoiceCreationService.php` | `createFromDeal()` を再利用してドラフト発行 |
| `database/migrations/2026_05_13_221557_add_vendor_metadata_to_invoices.php` | `invoices.vendor_metadata` (JSONB) 追加 |
| `resources/views/invoices/pdf.blade.php` | Refinitiv 専用レイアウトの分岐（`vendor_metadata` が NULL でない場合） |

### 3.2 API エンドポイント

#### `POST /api/v1/invoices/refinitiv/parse`

```
multipart/form-data:
  file: 注文書 PDF (mimes:pdf, max:10240KB)
```

**処理**:
1. `smalot/pdfparser` で PDF から生テキスト抽出
2. Claude Sonnet 4.6 (`config('services.anthropic.model')` 経由) に渡して JSON 化
3. `raw_text` を除いた抽出結果を JSON で返却

**レスポンス**:
```json
{
  "po_number": "...",
  "total_amount": 1234567,
  "description": "Aizen - JBIC - Market data consulting Apr-Jun2026",
  "requested_delivery_date": "2026-06-30",
  "amount_based_receipt": "...",
  "purchase_request_line": "...",
  "requester": "...",
  "request_number": "...",
  "plant_id": "...",
  "plant_name": "...",
  "tr_plant_id": "...",
  "ship_to_address_name": "...",
  "classification_domain": "...",
  "classification_code": "..."
}
```

> 保存は行わない（純粋にパース結果を返すだけ）。クライアント側はこの結果を編集して `issue` に投げる。

#### `POST /api/v1/invoices/refinitiv/issue`

```json
{
  "deal_id": 123,
  "year_month": "2026-05",
  "po_number": "5800XXXXXX",
  "vendor_metadata": {
    "description": "...",
    "requested_delivery_date": "2026-06-30",
    "amount_based_receipt": "...",
    "...": "..."
  },
  "issued_date": "2026-05-31"
}
```

**処理**:
1. `deal_id` の Deal を取得（テナント分離は GlobalScope 経由）
2. **同 deal × year_month に既存請求書があればエラー**（重複防止）
3. `InvoiceCreationService::createFromDeal()` に以下を渡して英文モードでドラフト発行
   - `order_number` = `po_number`
   - `vendor_metadata` = 任意 JSON
   - `language` = `'en'`（英文モード固定）
   - `subject_name` = `vendor_metadata.description`（PDF 件名行）
4. 作成した請求書を `lines` リレーション込みで返却

### 3.3 DB スキーマ

```sql
-- 2026_05_13_221557_add_vendor_metadata_to_invoices.php
ALTER TABLE invoices ADD COLUMN vendor_metadata JSONB NULL AFTER language;
```

- `vendor_metadata` が NULL でない請求書は **Refinitiv 専用 PDF レイアウト** で出力（`resources/views/invoices/pdf.blade.php` で分岐）
- 既存の `language` 列との組み合わせ:
  - `language='en'` AND `vendor_metadata IS NOT NULL` → Refinitiv 専用英文 PDF
  - `language='en'` AND `vendor_metadata IS NULL` → 通常の英文 PDF
  - `language='ja'`（既定） → 日本語 PDF

---

## 4. Claude プロンプト設計

`RefinitivPoParserService::extractWithClaude()` で構築。要旨:

- 入力: PDF 生テキスト（4,000 文字超は先頭から切詰）
- 出力: 上記 14 フィールドの JSON（不明値は `null`）
- 重要点: **PDF ② (S2604-RJ014) の「その他の情報」欄と完全に一致** するフィールド名で抽出することを指示
- `max_tokens: 1024`、タイムアウト: 60秒

Claude API キーは `services.anthropic.api_key`（`config/services.php`）から取得（env 直呼び廃止済）。

---

## 5. PDF レイアウト（請求書出力時）

`vendor_metadata IS NOT NULL` の請求書は以下の差分で PDF を生成:

| セクション | 通常の請求書 | Refinitiv 注文書由来 |
|---|---|---|
| 言語 | 日本語 | 英文 |
| 宛先 | 顧客名 + 「御中」 | 英文社名 |
| 発行元情報 | 日本語項目 | 英文項目（`tenants.invoice_issuer_*_en`）|
| 注文情報 | 注文 No. のみ | PO Number ＋「その他の情報」欄（申請者・申請番号・Plant.ID・分類コード等） |
| 銀行情報 | `bank_*_snapshot` | `tenants.invoice_issuer_bank_details_en`（複数行表示） |
| Sum Total | 太線・税込合計 | PDF ② 準拠（太線・1行表示） |

> 詳細は `resources/views/invoices/pdf.blade.php` の `@if($invoice->vendor_metadata)` 分岐を参照。

---

## 6. 運用上の注意

### 6.1 発行元設定の英文情報を必ず入力する

`/settings/invoice-issuer` の「英文情報」セクションが空欄だと、Refinitiv 専用 PDF の住所・銀行情報が空または日本語のまま出力されてしまう。Refinitiv 取引を始める前に必ず入力すること。

### 6.2 SES契約は事前に登録しておく

`/api/v1/invoices/refinitiv/issue` は **既存の SES契約 (deal_id) に紐付ける** 設計。Refinitiv からの新規発注の場合は事前に `/ses-contracts` で SES契約を登録してから本フローを使う。

### 6.3 同一 deal × year_month の重複請求書は発行不可

通常の請求書発行と同じ重複制約（`deal_id × year_month × doc_type='invoice'`）が適用される。誤って発行してしまった場合は、`draft` 状態なら削除可能。

### 6.4 注文書から請求書発行までの時間差

Refinitiv は注文書を SAP Business Network 経由で送ってくるが、メール側の到着タイミングと案件単価の決定タイミングがズレることがある。`InvoiceCreationService::createFromDeal()` は `SES契約.income_amount` を基本額として使うため、契約単価が更新されていない月にこのフローを使うと金額が古くなる可能性がある。**請求書ドラフト発行後、必ず金額を確認する**。

---

## 7. 今後の拡張余地

- 他取引先の注文書 PDF への流用（フィールド定義を取引先ごとにテーブル化すれば、Refinitiv 以外も同じパイプラインで取り込める）
- 注文書 PDF を `storage/po/{tenant_id}/{po_number}.pdf` に保存して請求書詳細から再閲覧できるようにする
- 抽出失敗時のフォールバック（Claude API ダウン時に手動入力に切替）

---

## 8. 関連コミット

| コミット | 内容 |
|---|---|
| `b66ea11` | 注文書PDF取込→請求書ドラフト発行フロー（初版） |
| `680e84c` | PDF生成エラー修正 + 英文モード適用 |
| `011a72b` | PDF②フォーマットに合わせて請求書テンプレ拡張 |
| `ceda93b` | PDF②準拠の英文化拡張 |
| `f6a3fe4` | PDF 改善（PO赤色 / 番号縦揃え / Sum Total 1行 / 案件名英文 / 1ページ） |
| `b473b1e` | ラベル右寄せ+コロン / 明細番号 1行化 / Bank Details 隙間 / Sum Total 太線 |
