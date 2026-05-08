@php
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

    $customerName = $invoice->customer_name_snapshot ?? $invoice->customer?->company_name ?? '';
    $customerPostal = $invoice->customer?->postal_code ?? '';
    $customerAddress = $invoice->customer_address_snapshot ?? $invoice->customer?->address ?? '';

    // 郵便番号を 7 桁の数字配列に分解（不足分は空文字）
    $postalDigits = array_pad(
        str_split(preg_replace('/[^0-9]/', '', (string) $customerPostal)),
        7, ''
    );

    // 「請求書在中」表示有無（呼び出し側で渡す。デフォルトは表示）
    $withZaichu = $withZaichu ?? true;
@endphp
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>長3封筒 {{ $invoice->invoice_number }}</title>
<style>
/* 長3封筒: 120mm × 235mm */
@page { size: 235mm 120mm; margin: 0; }
* { box-sizing: border-box; }
body {
    font-family: 'Noto Serif CJK JP', 'Noto Sans CJK JP', 'Yu Mincho', serif;
    margin: 0;
    padding: 0;
    width: 235mm;
    height: 120mm;
    color: #111;
    position: relative;
}

/* 郵便番号枠 — 左上（封筒を縦持ちした時の右上） */
.postal-area {
    position: absolute;
    top: 8mm;
    left: 12mm;
    display: flex;
    gap: 1.5mm;
}
.postal-mark {
    color: #c8102e;
    font-size: 11pt;
    font-weight: bold;
    margin-right: 2mm;
    letter-spacing: 0.05em;
}
.postal-box {
    width: 7mm;
    height: 9mm;
    border: 0.7pt solid #c8102e;
    text-align: center;
    line-height: 9mm;
    font-size: 14pt;
    color: #c8102e;
    font-weight: bold;
}
.postal-sep {
    width: 2mm;
    line-height: 9mm;
    text-align: center;
    color: #c8102e;
    font-size: 12pt;
}

/* 宛先住所 — 中央左 */
.recipient-address {
    position: absolute;
    top: 30mm;
    left: 30mm;
    font-size: 13pt;
    line-height: 1.7;
    max-width: 180mm;
}
.recipient-name {
    position: absolute;
    top: 50mm;
    left: 50mm;
    font-size: 22pt;
    letter-spacing: 0.05em;
    line-height: 1.4;
}

/* 「請求書在中」朱印 — 左下方 */
.zaichu {
    position: absolute;
    top: 22mm;
    left: 165mm;
    border: 1.2pt solid #c8102e;
    color: #c8102e;
    padding: 2mm 4mm;
    font-size: 13pt;
    letter-spacing: 0.4em;
    font-weight: bold;
}

/* 自社情報 — 右下 */
.issuer-area {
    position: absolute;
    bottom: 8mm;
    right: 12mm;
    border: 0.5pt solid #555;
    padding: 3mm 5mm;
    font-size: 9pt;
    line-height: 1.5;
    width: 92mm;
}
.issuer-area .logo { height: 8mm; vertical-align: middle; margin-right: 2mm; }
.issuer-area .name { font-size: 11pt; font-weight: bold; }
.issuer-area .url  { margin-top: 1mm; font-size: 8.5pt; word-break: break-all; }
.issuer-area .addr { word-break: break-all; }
</style>
</head>
<body>

{{-- 郵便番号枠 (例: 123-4567) --}}
<div class="postal-area">
    <span class="postal-mark">〒</span>
    @foreach([0,1,2] as $i)
        <div class="postal-box">{{ $postalDigits[$i] ?? '' }}</div>
    @endforeach
    <span class="postal-sep">-</span>
    @foreach([3,4,5,6] as $i)
        <div class="postal-box">{{ $postalDigits[$i] ?? '' }}</div>
    @endforeach
</div>

{{-- 「請求書在中」 --}}
@if($withZaichu)
    <div class="zaichu">請求書在中</div>
@endif

{{-- 宛先 --}}
<div class="recipient-address">{{ $customerAddress }}</div>
<div class="recipient-name">{{ $customerName }} 御中</div>

{{-- 自社情報 --}}
<div class="issuer-area">
    @if($logoData)
        <div style="margin-bottom: 1mm;"><img class="logo" src="{{ $logoData }}" alt="logo"></div>
    @endif
    <div class="name">{{ $invoice->issuer_name_snapshot }}</div>
    @php
        $addrParts = preg_split('/[ 　]/u', (string) $invoice->issuer_address_snapshot, 2);
    @endphp
    @if($invoice->issuer_postal_code_snapshot || !empty($addrParts[0]))
        <div>〒{{ $invoice->issuer_postal_code_snapshot }}　{{ $addrParts[0] ?? '' }}</div>
        @if(!empty($addrParts[1]))
            <div>　{{ $addrParts[1] }}</div>
        @endif
    @endif
    @if($invoice->issuer_tel_snapshot || $invoice->issuer_fax_snapshot)
        <div>
            @if($invoice->issuer_tel_snapshot)TEL：{{ $invoice->issuer_tel_snapshot }}@endif
            @if($invoice->issuer_tel_snapshot && $invoice->issuer_fax_snapshot)　@endif
            @if($invoice->issuer_fax_snapshot)FAX：{{ $invoice->issuer_fax_snapshot }}@endif
        </div>
    @endif
    @if($invoice->issuer_url_snapshot)
        <div class="url">{{ $invoice->issuer_url_snapshot }}</div>
    @endif
</div>

</body>
</html>
