@php
    use Illuminate\Support\Carbon;

    /**
     * 令和 表記の日付（"令和N年M月D日"）。元年は "令和元年" とする。
     */
    $reiwa = function (?Carbon $d): string {
        if (!$d) return '';
        $y = (int) $d->format('Y');
        $r = $y - 2018; // 令和元年 = 2019
        $era = $r === 1 ? '令和元' : "令和{$r}";
        return sprintf('%s年%d月%d日', $era, (int) $d->format('n'), (int) $d->format('j'));
    };

    /** 令和 表記 + 曜日付き（御支払期日用）"令和N年M月D日(曜)" */
    $reiwaDow = function (?Carbon $d): string {
        if (!$d) return '';
        $y = (int) $d->format('Y');
        $r = $y - 2018;
        $era = $r === 1 ? '令和元' : "令和{$r}";
        $dows = ['日','月','火','水','木','金','土'];
        $dow  = $dows[(int) $d->format('w')];
        return sprintf('%s年%d月%d日(%s)', $era, (int) $d->format('n'), (int) $d->format('j'), $dow);
    };

    $issuedAt = $invoice->issued_date instanceof Carbon ? $invoice->issued_date : null;
    $dueAt    = $invoice->due_date instanceof Carbon ? $invoice->due_date : null;

    // 英文モード（見積書のみ。'ja'/'en'）
    $isEnglish = ($invoice->language ?? 'ja') === 'en';
    /** 英文日付 "13 May 2026" / "30-Apr-2026" */
    $englishDate = function (?Carbon $d): string {
        return $d ? $d->format('j M Y') : '';
    };
    $refDate = function (?Carbon $d): string {
        return $d ? $d->format('j-M-Y') : '';
    };

    /**
     * ロゴデータの解決（base64 埋め込み）
     */
    $resolveLogoFromUrl = function (?string $url): ?string {
        if (!$url) return null;
        $contents = @file_get_contents($url);
        if ($contents === false) return null;
        $ext  = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'png';
        $mime = $ext === 'jpg' ? 'jpeg' : $ext;
        return 'data:image/' . $mime . ';base64,' . base64_encode($contents);
    };
    $resolveLogoFromPath = function (?string $path): ?string {
        if (!$path || !is_file($path) || !is_readable($path)) return null;
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = $ext === 'jpg' ? 'jpeg' : $ext;
        return 'data:image/' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    };

    $logoData = $resolveLogoFromUrl($invoice->issuer_logo_snapshot)
             ?? $resolveLogoFromPath(config('invoice.logo_path'));

    // 帳票種別（請求書 / 見積書 / 注文書）
    $docType = $invoice->doc_type ?? 'invoice';
    $isEstimate     = $docType === 'estimate';
    $isPurchaseOrder = $docType === 'purchase_order';

    // 注文請書モード: purchase_order 行を「請書」フォーマットで描画する
    // 同一データから 注文書 と 注文請書 の 2 種類の PDF を出し分ける
    $isAcknowledgement = ($isAcknowledgement ?? false) && $isPurchaseOrder;

    // Refinitiv 専用レイアウト（vendor_metadata あり時。請求書のみ適用）
    $vendorMeta = is_array($invoice->vendor_metadata ?? null) ? $invoice->vendor_metadata : null;
    $isRefinitiv = $vendorMeta !== null && !$isEstimate && !$isPurchaseOrder && !$isAcknowledgement;

    // 電子印画像（base64）
    //   - 請求書/注文書: 丸印
    //   - 見積書: 角印
    //   - 注文請書: なし（受領側が押印するため）
    $sealData = $isAcknowledgement
        ? null
        : ($isEstimate
            ? $resolveLogoFromUrl($invoice->issuer_square_seal_snapshot)
            : $resolveLogoFromUrl($invoice->issuer_round_seal_snapshot));

    // タイトル
    $docTitle = $isAcknowledgement ? '注　文　請　書'
              : ($isEstimate ? '御　見　積　書'
              : ($isPurchaseOrder ? '注　文　書'
              : ($isRefinitiv ? 'Invoice' : '請　求　書')));

    // 注文請書: 弊社/御社 wording の自動反転
    $swapWording = function (?string $text) use ($isAcknowledgement): string {
        if (!$text || !$isAcknowledgement) return (string) $text;
        return strtr($text, [
            '弊社指定'       => '御社ご指定',
            '弊社指示'       => 'お客様指示',
            '弊社'           => '御社',
            'ご請求下さい'   => 'ご請求致します',
            'ご請求ください' => 'ご請求致します',
        ]);
    };

    // 明細表のレイアウト（A4 1ページに収まる行数）
    // 注文書/請書/見積書 はテキスト情報が少なく明細表が広く取れるため行数を増やす
    $isExtendedLayout = $isPurchaseOrder || $isEstimate;
    // Refinitiv は明細 1 行のみ + その他情報セクションが続くため空行を最小限に
    $itemRows = $isRefinitiv ? 4 : ($isExtendedLayout ? 18 : 14);

    // 明細行を分類
    //   basicLine = sort_order=0 の非経費行（description は金額のみ保持／PDF側で「基本月額：」を付与）
    //   description のキーワード判定に依存しない（ユーザー編集でズレるバグを防ぐ）
    $basicLine     = $invoice->lines->first(fn($l) =>
        !$l->is_expense
        && (int) $l->sort_order === 0
    );
    $deductionLine = $invoice->lines->first(fn($l) => str_contains((string) $l->description, '控除') && !$l->is_expense);
    $overtimeLine  = $invoice->lines->first(fn($l) => str_contains((string) $l->description, '超過') && !$l->is_expense);
    $expenseLines  = $invoice->lines->where('is_expense', true)->values();
    // 上記以外（is_expense=false かつ 基本/控除/超過以外）
    $excludeIds = collect([$basicLine?->id, $deductionLine?->id, $overtimeLine?->id])
        ->filter()->values();
    $extraLines = $invoice->lines
        ->filter(fn($l) => !$l->is_expense
            && !$excludeIds->contains($l->id))
        ->values();

    $expenseTotal = $expenseLines->sum(fn($l) => (float) $l->amount);

    // 超過控除の閾値スナップショット
    $dedH = $invoice->client_deduction_hours_snapshot;
    $ovtH = $invoice->client_overtime_hours_snapshot;
    $unitMin = $invoice->settlement_unit_minutes_snapshot;
    $hasOvertimeRange = ($dedH !== null && $ovtH !== null);
    $rangeText = $hasOvertimeRange
        ? sprintf('%dH-%dH', (int) $dedH, (int) $ovtH)
        : null;
    $unitMinText = $unitMin ? sprintf('【精算単位：%d分】', (int) $unitMin) : '';
@endphp
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>{{ $isAcknowledgement ? '注文請書' : ($isEstimate ? '見積書' : ($isPurchaseOrder ? '注文書' : '請求書')) }} {{ $isAcknowledgement ? $invoice->acknowledgement_no : $invoice->invoice_number }}</title>
<style>
@page { size: A4; margin: 12mm 14mm 10mm 14mm; }
* { box-sizing: border-box; }
body {
    /* 国内帳票 (見積書/注文書/注文請書/請求書) は元紙ベースに合わせ明朝で出力 */
    font-family: 'Noto Serif CJK JP', 'Yu Mincho', 'MS Mincho', serif;
    font-size: 10pt;
    color: #111;
    margin: 0;
    padding: 0;
}
/* リフィニティブ英文請求書 / 英文見積書は元ネタに合わせ Helvetica 系で出力 (日本語混在は CJK ゴシックを fallback) */
body.sans-en {
    font-family: 'Helvetica', 'Arial', 'Noto Sans CJK JP', sans-serif;
}
.date-top { text-align: right; font-size: 10pt; margin-bottom: 2mm; }
.title {
    text-align: center;
    font-size: 20pt;
    letter-spacing: 0.5em;
    margin: 1mm 0 3mm 0;
    font-weight: normal;
}
/* 注文書/請書: タイトルと宛先（会社名）の間に余白を入れる */
.title.spaced { margin: 1mm 0 7mm 0; }
.head { width: 100%; border-collapse: collapse; margin-bottom: 2mm; }
.head-left, .head-right { vertical-align: top; width: 50%; }
.recipient {
    font-size: 13pt;
    border-bottom: 0.7pt solid #111;
    padding-bottom: 1.5mm;
    display: inline-block;
    min-width: 78mm;
}
.head-right { text-align: right; padding-left: 8mm; }
.logo { height: 12mm; display: block; margin-left: auto; margin-bottom: 1.5mm; }
.issuer-block { display: inline-block; text-align: left; font-size: 9pt; line-height: 1.45; position: relative; }
.issuer-name  { margin-top: 0.5mm; }
.issuer-seal {
    position: absolute;
    right: 8mm;
    top: 0mm;
    width: 16mm;
    height: 16mm;
    object-fit: contain;
}

/* 注文請書: 受領側(取引先)が記入する空欄ボックス。
   head 内の宛先「アイゼン御中」のすぐ下に配置する。 */
.ack-box {
    display: inline-block;
    margin-top: 5mm;
    border: 0.5pt solid #111;
    width: 64mm;
    height: 22mm;
    text-align: left;
    padding: 1.5mm 2mm;
    font-size: 8.5pt;
    color: #888;
    line-height: 1.9;
}
.ack-box .ack-label { display: block; }

.numbers-block {
    width: 100%;
    margin-bottom: 2mm;
    font-size: 9.5pt;
    line-height: 1.4;
    text-align: right;
}
/* 左メタ(納期等) と 番号ブロック(見積No.等) を同じ行に並べる */
.meta-numbers { width: 100%; border-collapse: collapse; margin-bottom: 2mm; }
.meta-numbers .meta-cell-left  { vertical-align: top; width: 65%; }
.meta-numbers .meta-cell-right { vertical-align: top; width: 35%; text-align: right; }
.meta-numbers .left-meta { margin-bottom: 0; }
.meta-numbers .numbers-block { margin-bottom: 0; }
.numbers-inner { display: inline-block; text-align: left; }
.numbers-inner .num-row { white-space: nowrap; }
.num-label { display: inline-block; min-width: 16mm; }
/* Refinitiv は英文ラベルが長いので幅を広げ、右寄せでコロン揃え */
.num-row.ref .num-label { min-width: 42mm; text-align: right; padding-right: 2mm; }
.num-row.ref.po .under { color: #d40000; font-weight: bold; }
.under {
    border-bottom: 0.5pt solid #111;
    padding: 0 2mm;
    min-width: 50mm;
    min-height: 1em;
    display: inline-block;
    text-align: left;
    line-height: 1.4;
}
.under:empty::before { content: "\00a0"; }

.left-meta { margin-bottom: 1mm; font-size: 9.5pt; line-height: 1.45; }
.left-meta .meta-label {
    display: inline-block;
    width: 18mm;
    text-align: justify;
    text-align-last: justify;
}

.total-wrap { margin: 1mm 0 1mm 0; text-align: left; }
.total-inner {
    display: inline-block;
    width: 56%;
    text-align: left;
}
.grand-total {
    border-bottom: 0.7pt solid #111;
    padding: 0.5mm 4mm;
    font-size: 12pt;
}
.gt-label  { letter-spacing: 0.4em; margin-right: 4mm; }
.gt-amount { font-size: 15pt; margin-right: 4mm; }
.gt-tax    { font-size: 10.5pt; }
.grand-total-sub { text-align: center; font-size: 9.5pt; margin-top: 0.5mm; }
/* Refinitiv 用: Sum Total を 1 行に収めるためラベル間隔を詰める + 下線を太く */
.grand-total.ref { white-space: nowrap; border-bottom-width: 1.8pt; }
.grand-total.ref .gt-label { letter-spacing: 0; margin-right: 3mm; font-size: 12pt; }
.grand-total.ref .gt-tax   { font-size: 10pt; }

.items { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 1mm; page-break-inside: avoid; }
.items th, .items td { border: 0.5pt solid #111; padding: 0.3mm 2mm; }
.items th { text-align: center; background: #fff; font-weight: normal; padding: 0.6mm 2mm; }
.col-name   { width: 56%; }
.col-qty    { width: 12%; text-align: center; }
.col-price  { width: 16%; }
.col-amount { width: 16%; }
/* Refinitiv 専用 6 列レイアウト */
.col-ref-no     { width: 12%; text-align: center; white-space: nowrap; }
.col-ref-name   { width: 38%; }
.col-ref-qty    { width: 11%; text-align: center; white-space: nowrap; }
.col-ref-um     { width: 7%;  text-align: center; }
.col-ref-price  { width: 16%; }
.col-ref-amount { width: 16%; }
.items td.num-center { text-align: center; font-variant-numeric: tabular-nums; }
.items td.name   { text-align: left; }
.items td.name.indent { padding-left: 6mm; }
.items td.qty    { text-align: right; padding-right: 4mm; }
.items td.num    { text-align: right; padding-right: 2mm; font-variant-numeric: tabular-nums; }
.items td.blank  { height: 2.5mm; }
.items td.muted  { color: #d33; }
.items tfoot td  { border: 0.5pt solid #111; }
.items tfoot tr:first-child td { border-top: 2.5pt solid #111; }
.items tfoot .sub-label { text-align: center; }
.items tfoot .total { font-weight: bold; }

.remarks-block {
    margin-top: 1mm;
    border: 0.5pt solid #111;
    padding: 1.5mm 3mm;
    font-size: 9pt;
    line-height: 1.5;
    page-break-inside: avoid;
}
.remarks-block .bank-row { white-space: nowrap; }
.remarks-block .bank-info { font-size: 8.5pt; letter-spacing: -0.02em; }
/* Refinitiv では Bank Details が長くなるので折り返し許容 */
.remarks-block .bank-row.wrap { white-space: normal; }
/* 注文書/請書 は備考エリアを大きく取る */
.remarks-block.tall { min-height: 22mm; }

/* Refinitiv 注文書 由来の補足情報セクション */
.ref-extra { width: 100%; border-collapse: collapse; margin-top: 1mm; font-size: 9pt; }
.ref-extra td { border: 0.5pt solid #111; padding: 1mm 2mm; }
.ref-extra .ref-section { background: #f4f4f4; font-weight: 600; font-size: 9.5pt; }
.ref-extra .ref-label { width: 35mm; white-space: nowrap; }
.ref-extra .ref-value { word-break: break-all; }
</style>
</head>
<body class="@if($isRefinitiv || $isEnglish) sans-en @endif">

<div class="date-top">{{ $isAcknowledgement ? '　　　/　　/　　' : ($isRefinitiv ? $refDate($issuedAt) : ($isEnglish ? $englishDate($issuedAt) : $reiwa($issuedAt))) }}</div>

<h1 class="title {{ ($isPurchaseOrder || $isEstimate) ? 'spaced' : '' }}">{{ $docTitle }}</h1>

<table class="head">
    <tr>
        <td class="head-left">
            <div class="recipient">
                @if($isAcknowledgement)
                    {{ $invoice->issuer_name_snapshot }}&nbsp;&nbsp;御中
                @else
                    {{ $invoice->customer_name_snapshot ?? $invoice->customer?->company_name }}&nbsp;&nbsp;御中
                @endif
            </div>
        </td>
        <td class="head-right">
            @if($isAcknowledgement)
                {{-- 注文請書: 受領側(取引先)が住所/TEL/会社名を記入する空欄ボックス --}}
                <div class="ack-box">
                    <div class="ack-label">住所</div>
                    <div class="ack-label">TEL・FAX</div>
                    <div class="ack-label">会社名</div>
                </div>
            @else
                @if($logoData)
                    <img class="logo" src="{{ $logoData }}" alt="logo">
                @endif
                <div class="issuer-block">
                    @if($isRefinitiv && !empty($tenant?->invoice_issuer_address_en))
                        {{-- Refinitiv 用: テナントに英文住所が設定されていればそれを使用 --}}
                        @foreach(preg_split('/\R/u', (string) $tenant->invoice_issuer_address_en) as $line)
                            @if(trim($line) !== '')<div>{{ $line }}</div>@endif
                        @endforeach
                    @else
                        @if($invoice->issuer_postal_code_snapshot)
                            <div>〒{{ $invoice->issuer_postal_code_snapshot }}</div>
                        @endif
                        @if($invoice->issuer_address_snapshot)
                            @php
                                // 住所は全角/半角スペースで 2 行に分割（番地と建物名の区切り想定）
                                $addrParts = preg_split('/[\s　]+/u', $invoice->issuer_address_snapshot, 2, PREG_SPLIT_NO_EMPTY);
                            @endphp
                            <div>{{ $addrParts[0] ?? '' }}</div>
                            @if(!empty($addrParts[1]))
                                <div>&nbsp;{{ $addrParts[1] }}</div>
                            @endif
                        @endif
                    @endif
                    @if($invoice->issuer_name_snapshot)
                        <div class="issuer-name">
                            @if($isAcknowledgement)
                                {{ $invoice->issuer_name_snapshot }}
                            @else
                                <strong>{{ $invoice->issuer_name_snapshot }}</strong>
                            @endif
                        </div>
                        @if($isRefinitiv)
                            <div class="issuer-name"><strong>{{ $tenant?->invoice_issuer_name_en ?: 'AIZEN SOLUTION' }}</strong></div>
                        @endif
                    @endif
                    @if($invoice->issuer_tel_snapshot || $invoice->issuer_fax_snapshot)
                        @if($isRefinitiv)
                            @if($invoice->issuer_tel_snapshot)<div>Phone Number：{{ $invoice->issuer_tel_snapshot }}</div>@endif
                            @if($invoice->issuer_fax_snapshot)<div>Fax：{{ $invoice->issuer_fax_snapshot }}</div>@endif
                        @else
                            <div>
                                @if($invoice->issuer_tel_snapshot)TEL：{{ $invoice->issuer_tel_snapshot }}@endif
                                @if($invoice->issuer_tel_snapshot && $invoice->issuer_fax_snapshot)　@endif
                                @if($invoice->issuer_fax_snapshot)FAX：{{ $invoice->issuer_fax_snapshot }}@endif
                            </div>
                        @endif
                    @endif
                    @if($isRefinitiv)
                        @php
                            $issuerEmail = $tenant?->invoice_issuer_email
                                        ?: (optional(\Illuminate\Support\Facades\Auth::user())->email
                                        ?? config('mail.from.address'));
                        @endphp
                        @if($issuerEmail)
                            <div>Email：{{ $issuerEmail }}</div>
                        @endif
                    @endif
                    @if($sealData)
                        <img class="issuer-seal" src="{{ $sealData }}" alt="seal">
                    @endif
                </div>
            @endif
        </td>
    </tr>
</table>

<table class="meta-numbers">
    <tr>
        <td class="meta-cell-left">
            @if(!$isRefinitiv)
                <div class="left-meta">
                    @if($invoice->delivery_date_text)
                        <div><span class="meta-label">納期</span>：&nbsp;&nbsp;{{ $swapWording($invoice->delivery_date_text) }}</div>
                    @endif
                    @if($invoice->delivery_place_text)
                        <div><span class="meta-label">納入場所</span>：&nbsp;&nbsp;{{ $swapWording($invoice->delivery_place_text) }}</div>
                    @endif
                    @if($invoice->payment_terms_text)
                        <div><span class="meta-label">支払期限</span>：&nbsp;&nbsp;{{ $swapWording($invoice->payment_terms_text) }}</div>
                    @endif
                    @if($isEstimate && $invoice->valid_until_text)
                        <div><span class="meta-label">有効期間</span>：&nbsp;&nbsp;{{ $invoice->valid_until_text }}</div>
                    @endif
                </div>
            @endif
        </td>
        <td class="meta-cell-right">
            <div class="numbers-block">
                <div class="numbers-inner">
                    @if($isAcknowledgement)
                        <div class="num-row"><span class="num-label">請書No.</span><span class="under">{{ $invoice->acknowledgement_no }}</span></div>
                        <div class="num-row"><span class="num-label">注文No.</span><span class="under">{{ $invoice->invoice_number }}</span></div>
                        <div class="num-row"><span class="num-label">見積No.</span><span class="under">{{ $invoice->quote_number }}</span></div>
                    @elseif($isEstimate)
                        <div class="num-row"><span class="num-label">見積No.</span><span class="under">{{ $invoice->invoice_number }}</span></div>
                    @elseif($isPurchaseOrder)
                        <div class="num-row"><span class="num-label">注文No.</span><span class="under">{{ $invoice->invoice_number }}</span></div>
                        <div class="num-row"><span class="num-label">見積No.</span><span class="under">{{ $invoice->quote_number }}</span></div>
                    @elseif($isRefinitiv)
                        <div class="num-row ref"><span class="num-label">Invoice Number：</span><span class="under">{{ $invoice->invoice_number }}</span></div>
                        <div class="num-row ref po"><span class="num-label">PO Number：</span><span class="under">{{ $invoice->order_number }}</span></div>
                        <div class="num-row ref"><span class="num-label">Quote number：</span><span class="under">{{ $invoice->quote_number }}</span></div>
                        <div class="num-row ref"><span class="num-label">Registration number：</span><span class="under">{{ $invoice->issuer_invoice_number_snapshot }}</span></div>
                    @else
                        <div class="num-row"><span class="num-label">請求No.</span><span class="under">{{ $invoice->invoice_number }}</span></div>
                        <div class="num-row"><span class="num-label">注文No.</span><span class="under">{{ $invoice->order_number }}</span></div>
                        <div class="num-row"><span class="num-label">見積No.</span><span class="under">{{ $invoice->quote_number }}</span></div>
                        <div class="num-row"><span class="num-label">登録番号</span><span class="under">{{ $invoice->issuer_invoice_number_snapshot }}</span></div>
                    @endif
                </div>
            </div>
        </td>
    </tr>
</table>

@if(!$isRefinitiv)
    <div class="total-wrap">
        <div class="total-inner">
            <div class="grand-total">
                <span class="gt-label">合&nbsp;計&nbsp;金&nbsp;額</span>
                <span class="gt-amount">￥{{ number_format((float) $invoice->total) }}</span>
                <span class="gt-tax">（税込）</span>
            </div>
            <div class="grand-total-sub">（内、消費税&nbsp;&nbsp;￥{{ number_format((float) $invoice->tax) }}）</div>
        </div>
    </div>
@else
    <div class="total-wrap">
        <div class="total-inner">
            <div class="grand-total ref">
                <span class="gt-label">Sum Total</span>
                <span class="gt-amount">￥{{ number_format((float) $invoice->total) }}</span>
                <span class="gt-tax">(Tax Included)</span>
            </div>
        </div>
    </div>
@endif

@php
    // 明細レイアウト（Sick サンプル準拠）
    $rows = [];

    // 件名行: language='en' の見積書は subject_name 自体が英文で保存されている
    if ($invoice->subject_name) {
        $rows[] = ['name' => $isEnglish
            ? '・' . $invoice->subject_name
            : '・件名：' . $invoice->subject_name];
    }
    $rows[] = ['blank' => true];

    // 作業期間: 英文時は DB に既に英文形式("1 Apr 2026 - 30 Jun 2026")で保存されている
    if ($invoice->work_period_text) {
        $rows[] = ['name' => $isEnglish
            ? '・' . $invoice->work_period_text
            : '・作業期間：' . $invoice->work_period_text];
    }
    $rows[] = ['blank' => true];

    // 基本月額行: 英文時は description が "X yen/month" で保存済み
    if ($basicLine) {
        $rows[] = [
            'name' => $isEnglish
                ? '・' . $basicLine->description
                : '・基本月額：' . $basicLine->description,
            'qty'  => rtrim(rtrim(number_format((float) $basicLine->quantity, 2), '0'), '.'),
            'unit_price' => $basicLine->unit_price,
            'amount' => $basicLine->amount,
        ];
    } else {
        $rows[] = ['name' => $isEnglish ? '・' : '・基本月額：'];
    }
    $rows[] = ['blank' => true];

    // 超過控除セクション
    if ($isExtendedLayout) {
        // SES台帳に超過控除データが存在する場合のみ表示
        $hasOvertimeDeduction =
            $invoice->client_overtime_hours_snapshot !== null
            || $invoice->client_deduction_hours_snapshot !== null
            || $invoice->client_overtime_unit_price_snapshot !== null
            || $invoice->client_deduction_unit_price_snapshot !== null
            || $invoice->settlement_unit_minutes_snapshot !== null;
        if ($hasOvertimeDeduction) {
            $rows[] = ['name' => '・超過/控除'];
            if ($invoice->client_overtime_hours_snapshot !== null) {
                $rows[] = ['name' => '・上限  ' . (int) $invoice->client_overtime_hours_snapshot . 'H以上'];
            }
            if ($invoice->client_deduction_hours_snapshot !== null) {
                $rows[] = ['name' => '・下限  ' . (int) $invoice->client_deduction_hours_snapshot . 'H未満'];
            }
            if ($invoice->client_overtime_unit_price_snapshot !== null) {
                $rows[] = ['name' => '・超過  ' . number_format((float) $invoice->client_overtime_unit_price_snapshot) . '円'];
            }
            if ($invoice->client_deduction_unit_price_snapshot !== null) {
                $rows[] = ['name' => '・控除  ' . number_format((float) $invoice->client_deduction_unit_price_snapshot) . '円'];
            }
            if ($invoice->settlement_unit_minutes_snapshot !== null) {
                $rows[] = ['name' => '・精算単位：' . (int) $invoice->settlement_unit_minutes_snapshot . '分'];
            }
            $rows[] = ['blank' => true];
        }

        // 支払サイト（注文書独自）
        if ($invoice->payment_terms_text) {
            $rows[] = ['name' => '・支払サイト：' . $swapWording($invoice->payment_terms_text)];
            $rows[] = ['blank' => true];
        }
        // 作業担当者は入力がある場合のみ印字（編集画面で空欄にすれば非表示）
        if ($invoice->engineer_name_snapshot) {
            $rows[] = ['name' => '・作業担当者：' . $invoice->engineer_name_snapshot];
        }
        if ($invoice->work_location) {
            $rows[] = ['name' => '・作業場所：' . $swapWording($invoice->work_location)];
        }
        if ($invoice->engineer_name_snapshot || $invoice->work_location) {
            $rows[] = ['blank' => true];
        }
    } else {
        // 請求書: 既存の短縮表示
        if ($rangeText) {
            $rows[] = ['name' => '・超過控除：' . $rangeText . $unitMinText];
            if ($invoice->client_overtime_unit_price_snapshot !== null) {
                $rows[] = [
                    'name'       => '超過単価：' . number_format((float) $invoice->client_overtime_unit_price_snapshot) . '円',
                    'indent'     => true,
                    'qty'        => $overtimeLine ? rtrim(rtrim(number_format((float) $overtimeLine->quantity, 2), '0'), '.') : null,
                    'unit_price' => $overtimeLine ? $overtimeLine->unit_price : null,
                    'amount'     => $overtimeLine ? $overtimeLine->amount : null,
                ];
            }
            if ($invoice->client_deduction_unit_price_snapshot !== null) {
                $rows[] = [
                    'name'       => '控除単価：-' . number_format((float) $invoice->client_deduction_unit_price_snapshot) . '円',
                    'indent'     => true,
                    'qty'        => $deductionLine ? rtrim(rtrim(number_format((float) $deductionLine->quantity, 2), '0'), '.') : null,
                    'unit_price' => $deductionLine ? $deductionLine->unit_price : null,
                    'amount'     => $deductionLine ? $deductionLine->amount : null,
                    'amount_negative' => true,
                ];
            }
            $rows[] = ['blank' => true];
        }

        if ($invoice->work_location)        { $rows[] = ['name' => '作業場所：' . $invoice->work_location]; $rows[] = ['blank' => true]; }
        if ($invoice->delivery_items_text)  { $rows[] = ['name' => '納品物：' . $invoice->delivery_items_text]; $rows[] = ['blank' => true]; }
        if ($invoice->engineer_name_snapshot) { $rows[] = ['name' => '作業者名：' . $invoice->engineer_name_snapshot]; $rows[] = ['blank' => true]; }
    }

    // 業務交通費 説明（複数行可）
    if ($invoice->transportation_note_text) {
        $rows[] = ['name' => '業務交通費：' . $swapWording($invoice->transportation_note_text)];
        $rows[] = ['blank' => true];
    }

    // 業務交通費 明細（経費）
    foreach ($expenseLines as $l) {
        $rows[] = [
            'name'       => $l->description,
            'qty'        => rtrim(rtrim(number_format((float) $l->quantity, 2), '0'), '.'),
            'unit_price' => $l->unit_price,
            'amount'     => $l->amount,
        ];
    }

    // 基本/控除/超過/経費以外の追加明細（手動編集分）
    foreach ($extraLines as $l) {
        $rows[] = [
            'name'       => $l->description,
            'qty'        => rtrim(rtrim(number_format((float) $l->quantity, 2), '0'), '.'),
            'unit_price' => $l->unit_price,
            'amount'     => $l->amount,
        ];
    }

    $padCount = max(0, $itemRows - count($rows));

    // フッター集計（課税分のみ）
    $byRate = [];
    foreach ($invoice->lines as $l) {
        if ($l->is_expense) continue;
        $r = (string) $l->tax_rate;
        $byRate[$r] = ($byRate[$r] ?? 0) + (float) $l->amount;
    }
    // 8% / 10% を必ず表示する（10% は計算、8% は空欄でも）
    $hasRate = function (string $r) use ($byRate) { return isset($byRate[$r]); };

    // Refinitiv 専用: 明細を 1 行に集約（subject_name + 基本月額 / 控除 / 超過 / 経費 のみ表示）
    if ($isRefinitiv) {
        $rows = [];
        if ($basicLine) {
            $rows[] = [
                'name'       => $invoice->subject_name ?: $basicLine->description,
                'qty'        => '1',
                'unit_price' => $basicLine->unit_price,
                'amount'     => $basicLine->amount,
            ];
        }
        if (!empty($deductionLine) && (float) $deductionLine->amount != 0) {
            $rows[] = [
                'name'       => '控除（精算下限未達）',
                'qty'        => rtrim(rtrim(number_format((float) $deductionLine->quantity, 2), '0'), '.'),
                'unit_price' => $deductionLine->unit_price,
                'amount'     => $deductionLine->amount,
                'amount_negative' => true,
            ];
        }
        if (!empty($overtimeLine) && (float) $overtimeLine->amount != 0) {
            $rows[] = [
                'name'       => '超過（精算上限超）',
                'qty'        => rtrim(rtrim(number_format((float) $overtimeLine->quantity, 2), '0'), '.'),
                'unit_price' => $overtimeLine->unit_price,
                'amount'     => $overtimeLine->amount,
            ];
        }
        foreach ($expenseLines as $l) {
            $rows[] = [
                'name'       => $l->description,
                'qty'        => rtrim(rtrim(number_format((float) $l->quantity, 2), '0'), '.'),
                'unit_price' => $l->unit_price,
                'amount'     => $l->amount,
            ];
        }
        $padCount = max(0, $itemRows - count($rows));
    }
@endphp

@if($isRefinitiv)
    {{-- Refinitiv 専用: 明細番号 / 品番説明 / 数量(単位) / UM / 単価 / 小計 --}}
    <table class="items items-ref">
        <thead>
            <tr>
                <th class="col-ref-no">明細番号</th>
                <th class="col-ref-name">品番／説明</th>
                <th class="col-ref-qty">数量(単位)</th>
                <th class="col-ref-um">UM</th>
                <th class="col-ref-price">単&nbsp;&nbsp;価</th>
                <th class="col-ref-amount">小&nbsp;&nbsp;計</th>
            </tr>
        </thead>
        <tbody>
            @php $refRowIndex = 0; @endphp
            @foreach($rows as $r)
                @if(!empty($r['blank']))
                    <tr><td></td><td class="name blank">&nbsp;</td><td></td><td></td><td></td><td></td></tr>
                @else
                    @php
                        $refRowIndex++;
                        $hasAmount = isset($r['amount']) && $r['amount'] !== null && (float) $r['amount'] != 0;
                    @endphp
                    <tr>
                        <td class="num-center">{{ $refRowIndex }}</td>
                        <td class="name @if(!empty($r['indent'])) indent @endif">{{ $r['name'] }}</td>
                        <td class="qty">{{ $r['qty'] ?? '' }}</td>
                        <td class="num-center">{{ $hasAmount ? 'EA' : '' }}</td>
                        <td class="num">{{ isset($r['unit_price']) && $r['unit_price'] !== null ? '￥'.number_format((float) $r['unit_price']) : '' }}</td>
                        <td class="num @if(!empty($r['amount_negative'])) muted @endif">
                            @if($hasAmount)
                                ￥{{ number_format((float) $r['amount']) }}
                            @endif
                        </td>
                    </tr>
                @endif
            @endforeach
            @for($i = 0; $i < $padCount; $i++)
                <tr><td></td><td class="name blank">&nbsp;</td><td></td><td></td><td></td><td></td></tr>
            @endfor
        </tbody>
        <tfoot>
            <tr><td></td><td></td><td></td><td colspan="2" class="sub-label">Total excl. Tax</td><td class="num">￥{{ number_format((float) $invoice->subtotal) }}</td></tr>
            <tr>
                <td></td><td></td><td></td>
                <td colspan="2" class="sub-label">Tax 10%</td>
                <td class="num">{{ $hasRate('0.1000') ? '￥'.number_format(round($byRate['0.1000'] * 0.10)) : '' }}</td>
            </tr>
            <tr>
                <td></td><td></td><td></td>
                <td colspan="2" class="sub-label">Transportation cost</td>
                <td class="num">{{ $expenseTotal > 0 ? '￥'.number_format($expenseTotal) : '' }}</td>
            </tr>
            <tr><td></td><td></td><td></td><td colspan="2" class="sub-label total">Sum Total</td><td class="num total">￥{{ number_format((float) $invoice->total) }}</td></tr>
        </tfoot>
    </table>
@else
    <table class="items">
        <thead>
            <tr>
                <th class="col-name">品&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;名</th>
                <th class="col-qty">数&nbsp;&nbsp;量</th>
                <th class="col-price">単&nbsp;&nbsp;価</th>
                <th class="col-amount">金&nbsp;&nbsp;額</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $r)
                @if(!empty($r['blank']))
                    <tr><td class="name blank">&nbsp;</td><td></td><td></td><td></td></tr>
                @else
                    <tr>
                        <td class="name @if(!empty($r['indent'])) indent @endif">{{ $r['name'] }}</td>
                        <td class="qty">{{ $r['qty'] ?? '' }}</td>
                        <td class="num">{{ isset($r['unit_price']) && $r['unit_price'] !== null ? '￥'.number_format((float) $r['unit_price']) : '' }}</td>
                        <td class="num @if(!empty($r['amount_negative'])) muted @endif">
                            @if(isset($r['amount']) && $r['amount'] !== null && (float) $r['amount'] != 0)
                                ￥{{ number_format((float) $r['amount']) }}
                            @endif
                        </td>
                    </tr>
                @endif
            @endforeach
            @for($i = 0; $i < $padCount; $i++)
                <tr><td class="name blank">&nbsp;</td><td></td><td></td><td></td></tr>
            @endfor
        </tbody>
        <tfoot>
            <tr><td></td><td colspan="2" class="sub-label">小　計</td><td class="num">￥{{ number_format((float) $invoice->subtotal) }}</td></tr>
            {{-- 8%（あれば計算、なければ空欄表示） --}}
            <tr>
                <td></td>
                <td colspan="2" class="sub-label">消費税（8%）</td>
                <td class="num">{{ $hasRate('0.0800') ? '￥'.number_format(round($byRate['0.0800'] * 0.08)) : '' }}</td>
            </tr>
            {{-- 10%（あれば計算、なければ空欄） --}}
            <tr>
                <td></td>
                <td colspan="2" class="sub-label">消費税（10%）</td>
                <td class="num">{{ $hasRate('0.1000') ? '￥'.number_format(round($byRate['0.1000'] * 0.10)) : '' }}</td>
            </tr>
            @if($expenseTotal > 0)
                <tr><td></td><td colspan="2" class="sub-label">経　費</td><td class="num">￥{{ number_format($expenseTotal) }}</td></tr>
            @endif
            <tr><td></td><td colspan="2" class="sub-label total">合　計</td><td class="num total">￥{{ number_format((float) $invoice->total) }}</td></tr>
        </tfoot>
    </table>
@endif

@if($isRefinitiv && $vendorMeta)
    @php
        // Refinitiv 注文書から転記する「その他の情報」セクション
        $refExtras = [
            '金額による受入'      => $vendorMeta['amount_based_receipt']  ?? null,
            '購入申請明細番号'    => $vendorMeta['purchase_request_line'] ?? null,
            '申請者'              => $vendorMeta['requester']             ?? null,
            '申請番号'            => $vendorMeta['request_number']        ?? null,
            'Plant.ID'            => $vendorMeta['plant_id']              ?? null,
            'Plant.Name'          => $vendorMeta['plant_name']            ?? null,
            'TR_PlantID'          => $vendorMeta['tr_plant_id']           ?? null,
            'Ship ToAddressName'  => $vendorMeta['ship_to_address_name']  ?? null,
            '分類ドメイン'        => $vendorMeta['classification_domain'] ?? null,
            '分類コード'          => $vendorMeta['classification_code']   ?? null,
        ];
        $refDeliveryDate = $vendorMeta['requested_delivery_date'] ?? null;
    @endphp
    <table class="ref-extra">
        @if($refDeliveryDate)
            <tr>
                <td class="ref-label" colspan="2">希望納入日：{{ $refDeliveryDate }}</td>
            </tr>
        @endif
        <tr>
            <td class="ref-section" colspan="2">その他の情報</td>
        </tr>
        @foreach($refExtras as $label => $value)
            <tr>
                <td class="ref-label">{{ $label }}：</td>
                <td class="ref-value">{{ $value ?? '' }}</td>
            </tr>
        @endforeach
    </table>
@endif

<div class="remarks-block {{ ($isPurchaseOrder || $isEstimate) ? 'tall' : '' }}">
    @if($isEstimate)
        <div>※御見積記載事項以外は別途御見積させていただきます。</div>
        @if($invoice->notes)
            <div style="margin-top:2mm;">{!! nl2br(e($invoice->notes)) !!}</div>
        @endif
    @elseif($isPurchaseOrder)
        {{-- 注文書 / 注文請書: 備考 のみ（御支払期日/振込先/手数料は請求書専用） --}}
        <div>備考</div>
        @if($invoice->notes)
            <div style="margin-top:2mm;">{!! nl2br(e($invoice->notes)) !!}</div>
        @endif
    @elseif($isRefinitiv)
        @php
            // Payment Terms: payment_site から「NET DUE X DAYS」形式を組み立てる
            $paymentSite = optional($invoice->deal?->sesContract)->payment_site ?? 50;
            $bankDetailsEn = $tenant?->invoice_issuer_bank_details_en ?: $invoice->issuer_bank_snapshot;
            // 項目間に空白を挿入: "Bank,Ltd(0001)Kawaguchi" → "Bank,Ltd (0001) Kawaguchi"
            if ($bankDetailsEn) {
                $bankDetailsEn = preg_replace('/(?<!\s)\(/', ' (', $bankDetailsEn);
                $bankDetailsEn = preg_replace('/\)(?!\s|$)/', ') ', $bankDetailsEn);
                $bankDetailsEn = preg_replace('/ +/', ' ', $bankDetailsEn);
            }
            $bankHolderEn  = $tenant?->invoice_issuer_bank_account_holder_en
                          ?: trim(($tenant?->invoice_issuer_bank_account_holder ?? '') . ' ' . ($tenant?->invoice_issuer_name_en ?: 'AIZEN SOLUTION'));
        @endphp
        <div>■Payment Terms: NET DUE {{ $paymentSite }} DAYS</div>
        @if($bankDetailsEn)
            <div class="bank-row">■Bank Details: <span class="bank-info">{{ $bankDetailsEn }}</span></div>
        @endif
        @if($bankHolderEn)
            <div class="bank-row">■Bank Account Name: <span class="bank-info">{{ $bankHolderEn }}</span></div>
        @endif
        @if($invoice->notes)
            <div style="margin-top:2mm;">{!! nl2br(e($invoice->notes)) !!}</div>
        @endif
    @else
        @if($dueAt)
            <div>■御支払期日：&nbsp;{{ $reiwaDow($dueAt) }}</div>
        @endif
        @if($invoice->issuer_bank_snapshot)
            <div class="bank-row">■お振込先：<span class="bank-info">{{ $invoice->issuer_bank_snapshot }}</span></div>
        @endif
        <div>※振込手数料はお客様にてご負担くださいますようお願い申し上げます。</div>
        @if($invoice->notes)
            <div style="margin-top:2mm;">{!! nl2br(e($invoice->notes)) !!}</div>
        @endif
    @endif
</div>

</body>
</html>
