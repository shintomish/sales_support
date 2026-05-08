@php
    use Illuminate\Support\Carbon;

    $issuedAt = $invoice->issued_date instanceof Carbon ? $invoice->issued_date : null;
    $coverDate = $issuedAt ?? Carbon::today();

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

    $items = $items ?? [['name' => '御請求書', 'count' => 1]];
@endphp
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>送付状 {{ $invoice->invoice_number }}</title>
<style>
@page { size: A4; margin: 16mm 18mm; }
* { box-sizing: border-box; }
body {
    font-family: 'Noto Sans CJK JP', 'Noto Sans JP', 'Yu Gothic', sans-serif;
    font-size: 10.5pt;
    color: #111;
    margin: 0;
    padding: 0;
    line-height: 1.6;
}

.date-top { text-align: right; font-size: 10pt; }

/* 顧客名（左、上段） */
.recipient {
    font-size: 13pt;
    margin-top: 8mm;
    margin-bottom: 8mm;
}

/* 発行者ブロック（顧客名の下、右寄せ） */
.issuer-wrap {
    text-align: right;
    margin-bottom: 8mm;
}
.issuer-block {
    display: inline-block;
    text-align: left;
    font-size: 9.5pt;
    line-height: 1.55;
    min-width: 80mm;
}
.issuer-logo {
    height: 12mm;
    display: block;
    margin-bottom: 1.5mm;
}

.title {
    text-align: center;
    font-size: 22pt;
    letter-spacing: 0.6em;
    margin: 12mm 0 12mm 0;
    font-weight: normal;
}

.greeting { margin: 0 4mm 8mm 4mm; }
.signoff  { text-align: right; margin: 0 4mm 6mm 0; }

.kichou { text-align: center; font-size: 12pt; margin: 6mm 0 4mm 0; }

.items-list { margin: 4mm 8mm 6mm 18mm; }
.items-list table { border-collapse: collapse; }
.items-list td { padding: 1mm 4mm; font-size: 11pt; }
.items-list td.name { padding-left: 0; }
.items-list td.count { text-align: right; padding-right: 0; }

.ijou { text-align: right; margin: 12mm 4mm 0 0; }
</style>
</head>
<body>

<div class="date-top">{{ $coverDate->format('Y年n月j日') }}</div>

<div class="recipient">{{ $invoice->customer_name_snapshot ?? $invoice->customer?->company_name }} 御中</div>

<div class="issuer-wrap">
    <div class="issuer-block">
        @if($logoData)
            <img class="issuer-logo" src="{{ $logoData }}" alt="logo">
        @endif
        @if($invoice->issuer_name_snapshot)
            <div>{{ $invoice->issuer_name_snapshot }}</div>
        @endif
        @if($invoice->issuer_postal_code_snapshot)
            <div>〒{{ $invoice->issuer_postal_code_snapshot }}　{{ $invoice->issuer_address_snapshot }}</div>
        @endif
        @if($invoice->issuer_tel_snapshot || $invoice->issuer_fax_snapshot)
            <div>
                @if($invoice->issuer_tel_snapshot)TEL：{{ $invoice->issuer_tel_snapshot }}@endif
                @if($invoice->issuer_tel_snapshot && $invoice->issuer_fax_snapshot)　@endif
                @if($invoice->issuer_fax_snapshot)FAX：{{ $invoice->issuer_fax_snapshot }}@endif
            </div>
        @endif
        @if($invoice->issuer_url_snapshot)
            <div>{{ $invoice->issuer_url_snapshot }}</div>
        @endif
    </div>
</div>

<h1 class="title">送　付　状</h1>

<div class="greeting">
    拝啓 ますます御健勝のこととお慶び申し上げます。平素は格別のご高配を賜り、厚くお礼申し上げます。<br>
    下記書類を同封いたしましたのでご確認の上、宜しくお願い申し上げます。
</div>
<div class="signoff">敬具</div>

<div class="kichou">記</div>

<div class="items-list">
    <table>
        @foreach($items as $it)
            <tr>
                <td class="name">・{{ $it['name'] }}</td>
                <td class="count">{{ $it['count'] }}通</td>
            </tr>
        @endforeach
    </table>
</div>

<div class="ijou">以上</div>

</body>
</html>
