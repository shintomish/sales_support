# 120 モバイルレスポンシブ デザイン指針

**最終更新**: 2026-05-15
**対象リポジトリ**: `~/sales_support_next/` (Next.js フロントエンド)
**関連 Issue**: shintomish/sales_support_next#29

---

## 1. 方針

### ブレイクポイント
- Tailwind CSS のデフォルト `md` (≥ 768px) を境界として **モバイル / デスクトップ** を切り分ける
- `sm` / `lg` / `xl` は原則使わない（複雑化を避ける）
- スマホ縦持ち＝デフォルト挙動、タブレット縦も `md` 未満として扱う

### モバイル優先 vs デスクトップ優先
- 既存画面は **デスクトップ優先**（無印クラス＝デスクトップ）で書かれているため、`md:` で復元する書き方を維持
- 例: `flex flex-col md:flex-row` ではなく、既存 `flex flex-row` を `flex flex-col md:flex-row` に書き換える方向
- 新規画面のみ「モバイル優先」を許容

### 適用範囲
- 営業事務担当者がスマホで日常的に触る画面を最優先
- PDF プレビュー・印刷レイアウトは **対象外**（A4 前提のまま）

---

## 2. 共通パターン集

### 2.1 テーブル横スクロール

スマホ画面では table が横幅オーバーするため、wrapper で横スクロール可能にする。

```tsx
<div
  className="bg-white border border-gray-200 rounded-lg overflow-auto"
  style={{ maxHeight: 'calc(100vh - 280px)' }}
>
  <table
    className="text-sm"
    style={{ width: '1100px', minWidth: '1100px', tableLayout: 'fixed' }}
  >
    {/* ... */}
  </table>
</div>
```

**ポイント:**
- `overflow-auto`（`overflow-x-auto` だけだと縦 sticky header が効かない）
- table 幅は **インライン style で固定**（Tailwind 任意値 `w-[1100px]` は JIT 生成漏れする実例あり）
- `tableLayout: 'fixed'` で列幅を確定（auto だと内容に引きずられて min-width が効かない）
- `maxHeight` で縦スクロール領域を確定し、`thead` を `sticky top-0` にできる

**落とし穴:** `overflow-x-auto` と `overflow-y-auto` を 1 要素に同居させると、片方が `visible` にならない CSS のペアリングルールに引っかかる。 **どちらも auto** が安全。

参考実装: `src/app/deliveries/page.tsx` 1464-1466 行 (一斉配信履歴 table)

---

### 2.2 アコーディオン展開時の横スクロール

行をタップしてアコーディオン展開する場合、展開後のコンテンツも横幅オーバーすることがある。展開コンテンツ内の header 行を 1 行で表示するには:

```tsx
<div className="flex flex-nowrap whitespace-nowrap items-center gap-2">
  {/* 送信メタ情報など */}
</div>
```

`flex-wrap` で折り返してしまうと縦に伸びて読みにくいため、`flex-nowrap` + `whitespace-nowrap` で 1 行確定にし、親の `overflow-auto` で横スクロールに乗せる。

参考実装: `src/app/deliveries/page.tsx` 1602 / 1653 行 (送信・受信 ヘッダー)

---

### 2.3 モーダルのレスポンシブ

作成・編集モーダルはスマホで縦に積む。

```tsx
<div
  data-modal-scroll
  className="bg-white rounded-lg shadow-xl w-full max-w-2xl p-4 md:p-6 max-h-[90vh] overflow-y-auto"
  onClick={(e) => e.stopPropagation()}
>
  {/* ヘッダー行: スマホで折り返し */}
  <div className="flex flex-wrap gap-2 md:gap-4 items-center">
    {/* タブ・切替・ラベル類 */}
  </div>

  {/* グリッド: スマホ 1 列 / デスクトップ 2 列 */}
  <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
    {/* フォームフィールド */}
  </div>
</div>
```

**書き換えパターン:**
- `p-6` → `p-4 md:p-6`
- `gap-4` → `gap-2 md:gap-4`
- `grid-cols-2` → `grid-cols-1 md:grid-cols-2`
- `grid-cols-3` → `grid-cols-1 md:grid-cols-3`
- `col-span-2` → `md:col-span-2`（モバイルでは 1 列なので span 解除）
- `flex gap-4` (右寄せ要素ありの行) → `flex flex-wrap gap-2 md:gap-4`
- 右寄せ要素には `md:ml-auto` を付与（スマホで折り返し時は左寄せに戻る）

---

### 2.4 Sticky フッター + ▲ TOP ボタン

長いモーダルでスクロールしてもアクションボタン（キャンセル / 作成）を常に出す。

```tsx
{/* モーダルの最後に配置 */}
<div className="sticky bottom-0 -mx-4 md:-mx-6 -mb-4 md:-mb-6 mt-6 px-4 md:px-6 py-3 bg-white border-t border-gray-200 flex items-center justify-between gap-2">
  <button
    type="button"
    onClick={(e) => {
      const sc = e.currentTarget.closest<HTMLElement>('[data-modal-scroll]');
      sc?.scrollTo({ top: 0, behavior: 'smooth' });
    }}
    className="text-xs text-gray-500 hover:text-gray-700 px-2 py-1"
  >
    ▲ TOP
  </button>
  <div className="flex gap-2">
    <Button variant="outline" onClick={() => setOpen(false)} disabled={busy}>キャンセル</Button>
    <Button onClick={handleSubmit} disabled={busy}>作成</Button>
  </div>
</div>
```

**ポイント:**
- 親モーダルに `data-modal-scroll` を付与しておき、▲ TOP は `closest()` でその要素を取得して scrollTo
- `-mx-4 md:-mx-6` でモーダルの padding を打ち消し、フッター背景を端まで伸ばす
- `-mb-4 md:-mb-6` で底辺の padding を消す（フッター自身が py-3 を持つ）
- `border-t` で本文との境界を明示

---

### 2.5 ページネーション sticky 化（タブ付きページ）

タブ付きの一覧ページで、各タブ内のページネーションをスマホで常に表示するパターン。タブ自体は固定し、コンテンツ部分だけスクロールさせる。

```tsx
<div className="flex flex-col h-screen">
  {/* ヘッダー: 固定 */}
  <div className="flex-shrink-0 px-6 pt-6">
    <h1>配信管理</h1>
  </div>

  {/* タブバー: 固定 */}
  <div className="flex-shrink-0 px-6 flex border-b border-gray-200">
    {/* タブボタン */}
  </div>

  {/* タブコンテンツ: スクロール可能 */}
  <div className="flex-1 overflow-y-auto px-6 pb-6">
    {/* 一覧 table */}

    {/* ページネーション: sticky bottom */}
    <div className="sticky bottom-0 bg-white border-t border-gray-200 py-3 -mx-6 px-6 flex items-center justify-between">
      <Pagination /* ... */ />
    </div>
  </div>
</div>
```

**ポイント:**
- `flex flex-col h-screen` でレイアウト全体を高さ確定
- `flex-shrink-0` でヘッダー・タブを固定、`flex-1` でコンテンツを残り全部にする
- `sticky bottom-0` はその直近の scroll container （= `overflow-y-auto` の div）に対して効く
- `-mx-6 px-6` で sticky 背景を画面端まで伸ばす

**ページネーション UI 統一:** prev/next のみではなく、**数字付き + prev/next** で全タブ統一する（提案スレッドも同様に変更済み）。

参考実装: `src/app/deliveries/page.tsx` 1110-1134, 1363, 1754, 1977 行

---

## 3. 対応済み画面一覧

| 画面 | パス | 対応内容 |
|---|---|---|
| 見積書一覧（作成モーダル） | `src/app/estimates/page.tsx` | グリッド 1 列化、padding、Sticky フッター + ▲ TOP |
| 注文書一覧（作成モーダル） | `src/app/purchase-orders/page.tsx` | 同上 |
| 請求書一覧（作成モーダル） | `src/app/invoices/page.tsx` | 同上 |
| 請求一覧表（作成モーダル） | `src/app/billing-summaries/page.tsx` | グリッド 1 列化、col-span 解除、Sticky フッター + ▲ TOP |
| 配信管理 | `src/app/deliveries/page.tsx` | タブ固定 + 各タブ scroll + sticky ページネーション、一斉配信履歴 table 横スクロール、アコーディオン header 1 行化、提案スレッドのページネーション形式統一 |
| サイドバー / ヘッダー | `src/components/Sidebar.tsx` ほか | （別件で対応済み・本ドキュメント対象外） |

---

## 4. 未対応 / TODO

### 帳票詳細ページ（編集・承認画面）

以下 4 ページはまだスマホ未対応:

| 画面 | パス |
|---|---|
| 見積書 詳細 | `src/app/estimates/[id]/page.tsx` |
| 注文書 詳細 | `src/app/purchase-orders/[id]/page.tsx` |
| 請求書 詳細 | `src/app/invoices/[id]/page.tsx` |
| SES 契約 詳細 | `src/app/ses-contracts/[id]/page.tsx` |

優先度は **見積書 → 注文書 → 請求書 → SES 契約** （事務担当者の触る順）。

### その他の候補
- ダッシュボード（カード並びの 1 列化）
- 名刺管理（OCR プレビューのスマホ対応）
- 案件マーケット公開ページ（060 で計画中）

---

## 5. 落とし穴・運用上の注意

### 5.1 Vercel deploy queue hang
連続して `git push` した後、本番に反映されない時はコードを疑う前に **Vercel dashboard の deployment queue** を確認する。`Initializing` のまま停止している deployment が 1 つあると、後続が全部 `Queued` で詰まる。該当 deployment を Cancel すれば自動で最新コミットが Building に進む。

実例: 2026-05-15、一斉配信履歴の横スクロール対応で 5 コミット連続 push したが、先頭の `307c486` が 58 分間 Initializing で hang していたため、後続 (`c72e267` → `4dc999a` → `08685a6` → `a1a4352` → `4ab9bdf`) が全て塞がれた。

### 5.2 `overflow-x` / `overflow-y` の同居
CSS 仕様上、片方が `auto` / `scroll` の時にもう片方を `visible` にできない（自動で `auto` に解釈される）。table wrapper で横スクロールしたい時は、両方とも `auto` で揃える（= `overflow-auto`）のが最も事故が少ない。

### 5.3 Tailwind 任意値クラスの JIT 漏れ
`w-[1100px]` / `min-w-[1100px]` のような任意値クラスは、ビルド時に JIT が拾い損ねる実例があった。固定幅が必須の箇所は **インライン `style={{ width: '1100px' }}`** を使う方が確実。

### 5.4 PDF レイアウトには適用しない
帳票 PDF（見積書・注文書・請求書）は **A4 紙固定レイアウト**。本ドキュメントの指針を PDF 側に適用するとレイアウト崩れるため対象外。PDF は `App\Services\Pdf\*` (Laravel 側) で生成される。

---

## 6. 改訂履歴

| 日付 | 内容 |
|---|---|
| 2026-05-15 | 初版作成。Issue #29 で実装済みの 5 画面のパターンを抽出 |
