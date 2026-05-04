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

    $issuedAt = $invoice->issued_date instanceof Carbon ? $invoice->issued_date : null;
    $dueAt    = $invoice->due_date instanceof Carbon ? $invoice->due_date : null;

    [$y, $m] = array_pad(explode('-', $invoice->year_month), 2, null);
    $periodStart = $y && $m ? Carbon::create((int) $y, (int) $m, 1) : null;
    $periodEnd   = $periodStart ? $periodStart->copy()->endOfMonth() : null;

    $logoPath = config('invoice.logo_path');
    $logoData = null;
    if ($logoPath && is_file($logoPath) && is_readable($logoPath)) {
        $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = $ext === 'jpg' ? 'jpeg' : $ext;
        $logoData = 'data:image/' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
    }
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
    font-size: 22pt;
    letter-spacing: 0.5em;
    margin: 2mm 0 5mm 0;
    font-weight: normal;
}
.head { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
.head-left, .head-right { vertical-align: top; width: 50%; }
.recipient {
    font-size: 13pt;
    border-bottom: 0.7pt solid #111;
    padding-bottom: 1.5mm;
    display: inline-block;
    min-width: 78mm;
}
.head-right { text-align: left; padding-left: 8mm; }
.logo { height: 11mm; display: block; margin-bottom: 1.5mm; }
.issuer-block { font-size: 9pt; line-height: 1.45; }
.issuer-name { text-align: right; margin-top: 0.5mm; }

.meta { width: 100%; border-collapse: collapse; margin-bottom: 3mm; font-size: 9.5pt; line-height: 1.55; }
.meta-left  { width: 60%; vertical-align: top; }
.meta-right { width: 40%; vertical-align: top; text-align: left; padding-left: 4mm; }
.meta-label { display: inline-block; }
.under { border-bottom: 0.5pt solid #111; padding: 0 2mm; }

.grand-total {
    border-bottom: 0.7pt solid #111;
    padding: 1mm 4mm;
    margin: 2mm 0 1mm 0;
    font-size: 12pt;
}
.gt-label  { letter-spacing: 0.4em; margin-right: 4mm; }
.gt-amount { font-size: 15pt; margin-right: 4mm; }
.gt-tax    { font-size: 10.5pt; }
.grand-total-sub { text-align: center; font-size: 9.5pt; margin-bottom: 2mm; }

.items { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 2mm; }
.items th, .items td { border: 0.5pt solid #111; padding: 0.6mm 2mm; }
.items th { text-align: center; background: #fff; font-weight: normal; }
.col-name   { width: 56%; }
.col-qty    { width: 12%; text-align: center; }
.col-price  { width: 16%; }
.col-amount { width: 16%; }
.items td.name   { text-align: left; }
.items td.qty    { text-align: right; padding-right: 4mm; }
.items td.num    { text-align: right; padding-right: 2mm; font-variant-numeric: tabular-nums; }
.items td.blank  { height: 4mm; }
.items tfoot td  { border: 0.5pt solid #111; }
.items tfoot .sub-label { text-align: center; }
.items tfoot .total { font-weight: bold; }

.remarks { width: 100%; border-collapse: collapse; margin-top: 1mm; font-size: 9pt; }
.remarks td { border: 0.5pt solid #111; padding: 1.5mm 3mm; vertical-align: top; }
.remarks-label { width: 14mm; text-align: center; background: #fde8e8; }
.remarks-body  { line-height: 1.5; }
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
                @if($invoice->issuer_tel_snapshot)
                    <div>TEL：{{ $invoice->issuer_tel_snapshot }}</div>
                @endif
                @if($invoice->issuer_name_snapshot)
                    <div class="issuer-name">{{ $invoice->issuer_name_snapshot }}</div>
                @endif
            </div>
        </td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td class="meta-left">
            @if($periodStart && $periodEnd)
                <div><span class="meta-label">対&nbsp;象&nbsp;期&nbsp;間</span>：&nbsp;&nbsp;{{ $periodStart->format('Y年m月d日') }} ～ {{ $periodEnd->format('Y年m月d日') }}</div>
            @endif
            @if(!empty($invoice->customer_address_snapshot))
                <div><span class="meta-label">設&nbsp;置&nbsp;場&nbsp;所</span>：&nbsp;&nbsp;{{ $invoice->customer_address_snapshot }}</div>
            @endif
            @if($dueAt)
                <div><span class="meta-label">支&nbsp;払&nbsp;期&nbsp;限</span>：&nbsp;&nbsp;{{ $dueAt->format('Y年n月j日') }}　現金振り込み</div>
            @endif
            <div><span class="meta-label">有&nbsp;効&nbsp;期&nbsp;間</span>：&nbsp;&nbsp;30日間</div>
        </td>
        <td class="meta-right">
            <div>請求書No.&nbsp;&nbsp;<span class="under">{{ $invoice->invoice_number }}</span></div>
            @if($invoice->issuer_invoice_number_snapshot)
                <div>登録番号.&nbsp;&nbsp;<span class="under">{{ $invoice->issuer_invoice_number_snapshot }}</span></div>
            @endif
        </td>
    </tr>
</table>

<div class="grand-total">
    <span class="gt-label">合&nbsp;計&nbsp;金&nbsp;額</span>
    <span class="gt-amount">￥{{ number_format((float) $invoice->total) }}-</span>
    <span class="gt-tax">（税込）</span>
</div>
<div class="grand-total-sub">（内、消費税&nbsp;&nbsp;￥{{ number_format((float) $invoice->tax) }}-）</div>

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
        @forelse($invoice->lines as $line)
            <tr>
                <td class="name">{{ $line->description }}</td>
                <td class="qty">{{ rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') }}{{ $line->unit ? ' '.$line->unit : '' }}</td>
                <td class="num">￥{{ number_format((float) $line->unit_price) }}</td>
                <td class="num">￥{{ number_format((float) $line->amount) }}</td>
            </tr>
        @empty
            <tr><td class="name">明細なし</td><td></td><td></td><td></td></tr>
        @endforelse
        @for($i = 0; $i < max(0, 6 - $invoice->lines->count()); $i++)
            <tr><td class="name blank">&nbsp;</td><td></td><td></td><td></td></tr>
        @endfor
    </tbody>
    <tfoot>
        <tr><td></td><td colspan="2" class="sub-label">小　計</td><td class="num">￥{{ number_format((float) $invoice->subtotal) }}</td></tr>
        <tr><td></td><td colspan="2" class="sub-label">消費税（10%）</td><td class="num">￥{{ number_format((float) $invoice->tax) }}</td></tr>
        <tr><td></td><td colspan="2" class="sub-label total">合　計</td><td class="num total">￥{{ number_format((float) $invoice->total) }}</td></tr>
    </tfoot>
</table>

<table class="remarks">
    <tr>
        <td class="remarks-label">備考</td>
        <td class="remarks-body">
            @if($invoice->notes)
                <div>{!! nl2br(e($invoice->notes)) !!}</div>
            @endif
            @if($invoice->issuer_bank_snapshot)
                <div>お振込先</div>
                <div>&nbsp;&nbsp;{{ $invoice->issuer_bank_snapshot }}</div>
            @endif
        </td>
    </tr>
</table>

</body>
</html>
