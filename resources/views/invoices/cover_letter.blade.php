@php
    use Illuminate\Support\Carbon;

    $issuedAt = $invoice->issued_date instanceof Carbon ? $invoice->issued_date : null;
    // 送付状の日付は請求日 or 今日
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

    // 同封物（呼び出し側で渡す。未指定なら御請求書のみ）
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

.head { width: 100%; border-collapse: collapse; margin-top: 4mm; }
.head td { vertical-align: top; }
.head-left  { width: 50%; }
.head-right { width: 50%; text-align: right; padding-left: 8mm; }
.recipient { font-size: 13pt; font-weight: normal; padding-top: 8mm; }
.logo { height: 12mm; display: block; margin-left: auto; margin-bottom: 1.5mm; }
.issuer-block { display: inline-block; text-align: left; font-size: 9.5pt; line-height: 1.45; }

.title {
    text-align: center;
    font-size: 22pt;
    letter-spacing: 0.6em;
    margin: 18mm 0 12mm 0;
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

.footer-mark { text-align: center; margin-top: 30mm; font-size: 11pt; color: #4a82c8; letter-spacing: 0.1em; }
</style>
</head>
<body>

<div class="date-top">{{ $coverDate->format('Y年n月j日') }}</div>

<table class="head">
    <tr>
        <td class="head-left">
            <div class="recipient">{{ $invoice->customer_name_snapshot ?? $invoice->customer?->company_name }} 御中</div>
        </td>
        <td class="head-right">
            @if($logoData)
                <img class="logo" src="{{ $logoData }}" alt="logo">
            @endif
            <div class="issuer-block">
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
                @if($invoice->issuer_name_snapshot)
                    <div>{{ $invoice->issuer_name_snapshot }}</div>
                @endif
            </div>
        </td>
    </tr>
</table>

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

<div class="footer-mark">AIZENSOL co.</div>

</body>
</html>
