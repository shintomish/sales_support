<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>請求書 {{ $invoice->invoice_number }}</title>
<style>
    @page { size: A4; margin: 16mm 14mm; }
    * { box-sizing: border-box; }
    body {
        font-family: 'Noto Sans CJK JP', 'Noto Sans JP', sans-serif;
        font-size: 10.5pt;
        color: #222;
        margin: 0;
    }
    h1 {
        text-align: center;
        font-size: 22pt;
        letter-spacing: 0.4em;
        margin: 0 0 16px;
        border-bottom: 2px solid #333;
        padding-bottom: 8px;
    }
    .meta-row { display: flex; justify-content: space-between; margin-bottom: 16px; }
    .meta-left  { width: 56%; }
    .meta-right { width: 40%; text-align: right; }
    .label { color: #666; font-size: 9pt; margin-right: 4px; }
    .value { font-weight: 600; }
    .customer-name { font-size: 14pt; font-weight: 700; padding: 4px 0; border-bottom: 1px solid #999; }
    .total-box {
        border: 2px solid #2563eb;
        padding: 12px 16px;
        margin: 16px 0;
        text-align: center;
    }
    .total-box .label { font-size: 10pt; color: #2563eb; }
    .total-box .amount { font-size: 22pt; font-weight: 700; color: #1e40af; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 12px; }
    table.lines th, table.lines td {
        border: 1px solid #999;
        padding: 6px 8px;
        font-size: 10pt;
    }
    table.lines th { background: #f1f5f9; text-align: center; }
    table.lines td.num { text-align: right; font-variant-numeric: tabular-nums; }
    table.lines td.center { text-align: center; }
    table.summary { width: 50%; margin-left: auto; margin-top: 12px; border-collapse: collapse; }
    table.summary td { padding: 4px 8px; font-size: 10pt; }
    table.summary td.label { color: #555; }
    table.summary td.num { text-align: right; font-variant-numeric: tabular-nums; }
    table.summary tr.grand td { border-top: 2px solid #333; font-weight: 700; font-size: 12pt; }
    .issuer { margin-top: 28px; padding: 10px; border-top: 1px solid #999; font-size: 9.5pt; }
    .issuer .name { font-weight: 700; font-size: 11pt; margin-bottom: 4px; }
    .notes { margin-top: 16px; padding: 8px; background: #f9fafb; font-size: 9pt; white-space: pre-wrap; }
</style>
</head>
<body>

<h1>御請求書</h1>

<div class="meta-row">
    <div class="meta-left">
        <div class="customer-name">{{ $invoice->customer_name_snapshot ?? $invoice->customer?->company_name }} 御中</div>
        @if($invoice->customer_address_snapshot)
            <div style="margin-top:6px; font-size:9.5pt; color:#555;">{{ $invoice->customer_address_snapshot }}</div>
        @endif
    </div>
    <div class="meta-right">
        <div><span class="label">請求書番号:</span><span class="value">{{ $invoice->invoice_number }}</span></div>
        <div><span class="label">発行日:</span><span class="value">{{ optional($invoice->issued_date)->format('Y年n月j日') }}</span></div>
        @if($invoice->due_date)
            <div><span class="label">支払期限:</span><span class="value">{{ $invoice->due_date->format('Y年n月j日') }}</span></div>
        @endif
        <div><span class="label">対象:</span><span class="value">{{ $invoice->year_month }}</span></div>
    </div>
</div>

<div class="total-box">
    <div class="label">ご請求金額（税込）</div>
    <div class="amount">¥{{ number_format((float) $invoice->total) }}</div>
</div>

<table class="lines">
    <thead>
        <tr>
            <th style="width:50%;">摘要</th>
            <th style="width:8%;">数量</th>
            <th style="width:8%;">単位</th>
            <th style="width:14%;">単価</th>
            <th style="width:8%;">税率</th>
            <th style="width:12%;">金額</th>
        </tr>
    </thead>
    <tbody>
        @forelse($invoice->lines as $line)
            <tr>
                <td>{{ $line->description }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') }}</td>
                <td class="center">{{ $line->unit }}</td>
                <td class="num">¥{{ number_format((float) $line->unit_price) }}</td>
                <td class="center">{{ ((float) $line->tax_rate) == 0 ? '非課税' : (((int) ((float) $line->tax_rate * 100)) . '%') }}</td>
                <td class="num">¥{{ number_format((float) $line->amount) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="center" style="color:#999;">明細なし</td></tr>
        @endforelse
    </tbody>
</table>

<table class="summary">
    @php
        $byRate = [];
        foreach ($invoice->lines as $l) {
            $rate = (string) $l->tax_rate;
            if (!isset($byRate[$rate])) $byRate[$rate] = 0;
            $byRate[$rate] += (float) $l->amount;
        }
    @endphp
    <tr>
        <td class="label">小計（税抜）</td>
        <td class="num">¥{{ number_format((float) $invoice->subtotal) }}</td>
    </tr>
    @foreach($byRate as $rate => $sub)
        @php
            $rateF = (float) $rate;
            $tax = $rateF == 0 ? 0 : round($sub * $rateF);
            $label = $rateF == 0 ? '非課税分' : (((int) ($rateF * 100)) . '% 対象');
        @endphp
        <tr>
            <td class="label">{{ $label }} 小計</td>
            <td class="num">¥{{ number_format($sub) }}</td>
        </tr>
        @if($rateF > 0)
            <tr>
                <td class="label">{{ ((int) ($rateF * 100)) }}% 消費税</td>
                <td class="num">¥{{ number_format($tax) }}</td>
            </tr>
        @endif
    @endforeach
    <tr class="grand">
        <td class="label">合計</td>
        <td class="num">¥{{ number_format((float) $invoice->total) }}</td>
    </tr>
</table>

@if($invoice->notes)
    <div class="notes">{{ $invoice->notes }}</div>
@endif

<div class="issuer">
    @if($invoice->issuer_name_snapshot)
        <div class="name">{{ $invoice->issuer_name_snapshot }}</div>
    @endif
    @if($invoice->issuer_postal_code_snapshot)〒{{ $invoice->issuer_postal_code_snapshot }} @endif
    @if($invoice->issuer_address_snapshot){{ $invoice->issuer_address_snapshot }}@endif
    @if($invoice->issuer_tel_snapshot)<br>TEL: {{ $invoice->issuer_tel_snapshot }} @endif
    @if($invoice->issuer_invoice_number_snapshot)<br>登録番号: {{ $invoice->issuer_invoice_number_snapshot }}@endif
    @if($invoice->issuer_bank_snapshot)<br>振込先: {{ $invoice->issuer_bank_snapshot }}@endif
</div>

</body>
</html>
