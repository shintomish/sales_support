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

    // 明細表のレイアウト（A4 1ページに収まる行数）
    $itemRows = 14;

    // 明細行を分類
    $basicLine     = $invoice->lines->first(fn($l) => str_contains((string) $l->description, '基本月額'));
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
<title>請求書 {{ $invoice->invoice_number }}</title>
<style>
@page { size: A4; margin: 12mm 14mm 10mm 14mm; }
* { box-sizing: border-box; }
body {
    font-family: 'Noto Sans CJK JP', 'Noto Sans JP', 'Yu Gothic', sans-serif;
    font-size: 10pt;
    color: #111;
    margin: 0;
    padding: 0;
}
.date-top { text-align: right; font-size: 10pt; margin-bottom: 2mm; }
.title {
    text-align: center;
    font-size: 20pt;
    letter-spacing: 0.5em;
    margin: 1mm 0 3mm 0;
    font-weight: normal;
}
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
.issuer-block { display: inline-block; text-align: left; font-size: 9pt; line-height: 1.45; }
.issuer-name  { margin-top: 0.5mm; }

.numbers-block {
    width: 100%;
    margin-bottom: 2mm;
    font-size: 9.5pt;
    line-height: 1.4;
    text-align: right;
}
.numbers-inner { display: inline-block; text-align: left; }
.numbers-inner .num-row { white-space: nowrap; }
.num-label { display: inline-block; min-width: 16mm; }
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

.items { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 1mm; page-break-inside: avoid; }
.items th, .items td { border: 0.5pt solid #111; padding: 0.3mm 2mm; }
.items th { text-align: center; background: #fff; font-weight: normal; padding: 0.6mm 2mm; }
.col-name   { width: 56%; }
.col-qty    { width: 12%; text-align: center; }
.col-price  { width: 16%; }
.col-amount { width: 16%; }
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
</style>
</head>
<body>

<div class="date-top">{{ $reiwa($issuedAt) }}</div>

<h1 class="title">請　求　書</h1>

<table class="head">
    <tr>
        <td class="head-left">
            <div class="recipient">{{ $invoice->customer_name_snapshot ?? $invoice->customer?->company_name }}&nbsp;&nbsp;御中</div>
        </td>
        <td class="head-right">
            @if($logoData)
                <img class="logo" src="{{ $logoData }}" alt="logo">
            @endif
            <div class="issuer-block">
                @if($invoice->issuer_postal_code_snapshot)
                    <div>〒{{ $invoice->issuer_postal_code_snapshot }}</div>
                @endif
                @if($invoice->issuer_address_snapshot)
                    <div>{{ $invoice->issuer_address_snapshot }}</div>
                @endif
                @if($invoice->issuer_tel_snapshot || $invoice->issuer_fax_snapshot)
                    <div>
                        @if($invoice->issuer_tel_snapshot)TEL：{{ $invoice->issuer_tel_snapshot }}@endif
                        @if($invoice->issuer_tel_snapshot && $invoice->issuer_fax_snapshot)　@endif
                        @if($invoice->issuer_fax_snapshot)FAX：{{ $invoice->issuer_fax_snapshot }}@endif
                    </div>
                @endif
                @if($invoice->issuer_name_snapshot)
                    <div class="issuer-name">{{ $invoice->issuer_name_snapshot }}</div>
                @endif
            </div>
        </td>
    </tr>
</table>

<div class="numbers-block">
    <div class="numbers-inner">
        <div class="num-row"><span class="num-label">請求No.</span><span class="under">{{ $invoice->invoice_number }}</span></div>
        <div class="num-row"><span class="num-label">注文No.</span><span class="under">{{ $invoice->order_number }}</span></div>
        <div class="num-row"><span class="num-label">見積No.</span><span class="under">{{ $invoice->quote_number }}</span></div>
        <div class="num-row"><span class="num-label">登録番号</span><span class="under">{{ $invoice->issuer_invoice_number_snapshot }}</span></div>
    </div>
</div>

<div class="left-meta">
    @if($invoice->delivery_date_text)
        <div><span class="meta-label">納期</span>：&nbsp;&nbsp;{{ $invoice->delivery_date_text }}</div>
    @endif
    @if($invoice->delivery_place_text)
        <div><span class="meta-label">納入場所</span>：&nbsp;&nbsp;{{ $invoice->delivery_place_text }}</div>
    @endif
    @if($invoice->payment_terms_text)
        <div><span class="meta-label">支払期限</span>：&nbsp;&nbsp;{{ $invoice->payment_terms_text }}</div>
    @endif
</div>

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

@php
    // 明細レイアウト（Sick サンプル準拠）
    $rows = [];

    if ($invoice->subject_name) $rows[] = ['name' => '・件名：' . $invoice->subject_name];
    $rows[] = ['blank' => true];

    if ($invoice->work_period_text) $rows[] = ['name' => '・作業期間：' . $invoice->work_period_text];
    $rows[] = ['blank' => true];

    if ($basicLine) {
        $rows[] = [
            'name' => '・基本月額：' . $basicLine->description,
            'qty'  => rtrim(rtrim(number_format((float) $basicLine->quantity, 2), '0'), '.'),
            'unit_price' => $basicLine->unit_price,
            'amount' => $basicLine->amount,
        ];
    } else {
        $rows[] = ['name' => '・基本月額：'];
    }
    $rows[] = ['blank' => true];

    // 超過控除セクション
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

    // 業務交通費 説明（複数行可）
    if ($invoice->transportation_note_text) {
        $rows[] = ['name' => '業務交通費：' . $invoice->transportation_note_text];
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
@endphp

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

<div class="remarks-block">
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
</div>

</body>
</html>
