@php
    /** @var array $data */
    $sections = $data['sections'] ?? [];
    $appUrl   = rtrim(config('app.frontend_url') ?? config('app.url'), '/');
@endphp
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>日次レポート {{ $data['target_date'] ?? '' }}</title>
<style>
body { font-family: 'Hiragino Kaku Gothic ProN', 'Yu Gothic', 'Meiryo', sans-serif; font-size: 14px; color: #222; line-height: 1.6; max-width: 720px; margin: 0 auto; padding: 16px; }
h1 { font-size: 18px; border-bottom: 2px solid #2563eb; padding-bottom: 6px; margin-top: 0; }
h2 { font-size: 15px; margin-top: 24px; padding: 4px 8px; border-radius: 4px; }
.h2-blue   { background: #eff6ff; color: #1e40af; }
.h2-yellow { background: #fef3c7; color: #92400e; }
.h2-green  { background: #ecfdf5; color: #065f46; }
.h2-gray   { background: #f3f4f6; color: #374151; }
.summary { background: #fefce8; border-left: 4px solid #eab308; padding: 10px 14px; border-radius: 4px; margin: 12px 0 20px 0; white-space: pre-line; }
.kv { color: #6b7280; font-size: 13px; }
.match-item { padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 4px; margin-bottom: 6px; background: #fff; }
.score { display: inline-block; background: #2563eb; color: #fff; font-weight: bold; padding: 1px 6px; border-radius: 3px; margin-right: 6px; font-size: 12px; }
.expire-row { padding: 4px 0; border-bottom: 1px dashed #e5e7eb; }
.expire-row:last-child { border-bottom: 0; }
.expire-soon { color: #b91c1c; font-weight: bold; }
.expire-mid  { color: #b45309; }
.footer { margin-top: 30px; padding-top: 12px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 11px; }
a { color: #2563eb; }
</style>
</head>
<body>

<h1>📊 日次レポート ｜ {{ $data['target_date'] ?? '' }}</h1>
<div class="kv">要対応件数: {{ $data['total_action_items'] ?? 0 }} 件</div>

@if(!empty($data['ai_summary']))
    <div class="summary">
        <strong>🤖 今日のアクション（AI 提案）</strong><br>
        {!! nl2br(e($data['ai_summary'])) !!}
    </div>
@endif

{{-- 受信メール件数 --}}
@if(isset($sections['inbox']))
    @php $s = $sections['inbox']; @endphp
    <h2 class="h2-gray">📬 昨日の受信メール（合計 {{ $s['count'] }} 件）</h2>
    <ul>
        <li>技術者紹介: <strong>{{ $s['engineer'] }}</strong> 件</li>
        <li>案件紹介: <strong>{{ $s['project'] }}</strong> 件</li>
        <li>その他: {{ $s['other'] }} 件</li>
    </ul>
@endif

{{-- 新規SESマッチング候補 --}}
@if(isset($sections['matches']))
    @php $s = $sections['matches']; @endphp
    <h2 class="h2-blue">🔵 新規SES候補（要確認） {{ $s['count'] }} 件</h2>
    @foreach($s['top'] as $m)
        <div class="match-item">
            <span class="score">⭐{{ $m['score'] }}</span>
            <strong>{{ $m['name'] }}</strong>
            @if($m['unit_price_max'])　<span class="kv">({{ $m['unit_price_max'] }}万)</span>@endif
            @if($m['skills_summary'])<br><span class="kv">{{ $m['skills_summary'] }}</span>@endif
            <br><span class="kv">受信: {{ $m['received_at'] }}
                <a href="{{ $appUrl }}/engineer-mail-sources/{{ $m['id'] }}">確認する →</a>
            </span>
        </div>
    @endforeach
    @if($s['count'] > count($s['top']))
        <div class="kv">…他 {{ $s['count'] - count($s['top']) }} 件</div>
    @endif
@endif

{{-- 提案メール送信実績 --}}
@if(isset($sections['delivery']))
    @php $s = $sections['delivery']; @endphp
    <h2 class="h2-green">📤 昨日の提案メール送信（合計 {{ $s['count'] }} 件）</h2>
    <ul>
        <li>送信成功: <strong>{{ $s['sent'] }}</strong> 件</li>
        @if($s['failed'] > 0)<li style="color:#b91c1c;">送信失敗: <strong>{{ $s['failed'] }}</strong> 件</li>@endif
        @if($s['replied'] > 0)<li>返信受信: <strong>{{ $s['replied'] }}</strong> 件</li>@endif
    </ul>
@endif

{{-- 期限切れ間近のSES契約 --}}
@if(isset($sections['expiring']))
    @php $s = $sections['expiring']; @endphp
    <h2 class="h2-yellow">🟡 期限切れ間近のSES契約（30日以内 {{ $s['count'] }} 件）</h2>
    @foreach($s['list'] as $c)
        @php
            $cls = $c['days_left'] <= 15 ? 'expire-soon' : 'expire-mid';
        @endphp
        <div class="expire-row">
            <span class="{{ $cls }}">あと {{ $c['days_left'] }} 日</span>
            <span class="kv">({{ $c['end_date'] }})</span>
            　{{ $c['engineer_name'] }}
            @if($c['customer_name']) / {{ $c['customer_name'] }}@endif
            @if($c['deal_title'])    / {{ $c['deal_title'] }}@endif
        </div>
    @endforeach
@endif

@if(empty($sections))
    <p style="color:#6b7280;">本日特筆すべき動きはありませんでした。</p>
@endif

<div class="footer">
    このレポートは <a href="{{ $appUrl }}/settings/report-recipients">配信先設定</a> から購読停止できます。<br>
    送信元: 株式会社アイゼン・ソリューション 営業支援システム
</div>

</body>
</html>
