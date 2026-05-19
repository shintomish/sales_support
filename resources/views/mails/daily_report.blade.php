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
.h2-purple { background: #f5f3ff; color: #5b21b6; }
.h2-yellow { background: #fef3c7; color: #92400e; }
.h2-green  { background: #ecfdf5; color: #065f46; }
.h2-gray   { background: #f3f4f6; color: #374151; }
.summary { background: #fefce8; border-left: 4px solid #eab308; padding: 10px 14px; border-radius: 4px; margin: 12px 0 20px 0; line-height: 1.5; }
.summary p { margin: 0 0 4px 0; }
.summary p:last-child { margin-bottom: 0; }
.kv { color: #6b7280; font-size: 13px; }
.match-item { padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 4px; margin-bottom: 6px; background: #fff; }
.score { display: inline-block; background: #2563eb; color: #fff; font-weight: bold; padding: 1px 6px; border-radius: 3px; margin-right: 6px; font-size: 12px; }
.score-match { background: #16a34a; }
.parent-card { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; margin-bottom: 12px; background: #fff; }
.parent-card .parent-head { font-weight: bold; margin-bottom: 6px; }
.match-list { margin-top: 6px; padding-left: 14px; border-left: 3px solid #e5e7eb; }
.match-row { padding: 4px 0; font-size: 13px; }
.expire-row { padding: 4px 0; border-bottom: 1px dashed #e5e7eb; }
.expire-row:last-child { border-bottom: 0; }
.expire-soon { color: #b91c1c; font-weight: bold; }
.expire-mid  { color: #b45309; }
.footer { margin-top: 30px; padding-top: 12px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 11px; }
a { color: #2563eb; }
.kvbox { background: #f9fafb; padding: 8px 12px; border-radius: 4px; font-size: 13px; color: #4b5563; margin-top: 4px; }
</style>
</head>
<body>

<h1>📊 日次レポート ｜ {{ $data['target_date'] ?? '' }}</h1>
<div class="kvbox">
    要対応合計: <strong>{{ $data['action_total'] ?? 0 }} 件</strong>
    <span class="kv">（有効と思われる案件・技術者メール + 期限切れ間近のSES契約）</span>
</div>

@if(!empty($data['ai_summary']))
    <div class="summary">
        <strong>🤖 今日のアクション（AI 提案）</strong>
        @foreach(preg_split("/\r\n|\r|\n/", $data['ai_summary']) as $ln)
            @if(trim($ln) !== '')
                <p>{{ $ln }}</p>
            @endif
        @endforeach
    </div>
@endif

{{-- 受信メール件数 --}}
@if(isset($sections['inbox']))
    @php $s = $sections['inbox']; @endphp
    <h2 class="h2-gray">📬 受信メール（{{ $data['target_date'] }} 分　合計 {{ $s['count'] }} 件）</h2>
    <ul>
        <li>技術者紹介: <strong>{{ $s['engineer'] }}</strong> 件</li>
        <li>案件紹介: <strong>{{ $s['project'] }}</strong> 件</li>
        <li>その他: {{ $s['other'] }} 件</li>
    </ul>
@endif

{{-- 有効と思われるメールリスト（案件） --}}
@if(isset($sections['effective_project_mails']))
    @php $s = $sections['effective_project_mails']; @endphp
    <h2 class="h2-purple">📨 有効と思われるメールリスト（案件）{{ $s['count'] }} 件　<span class="kv" style="font-weight:normal;">条件：過去3日 スコア70+</span></h2>
    @foreach($s['list'] as $p)
        <div class="parent-card">
            <div class="parent-head">
                <span class="score">⭐{{ $p['score'] }}</span>
                {{ $p['title'] }}
                @if($p['customer_name'])<span class="kv">　/　{{ $p['customer_name'] }}</span>@endif
                @if($p['unit_price_max'])　<span class="kv">({{ $p['unit_price_max'] }}万)</span>@endif
            </div>
            @if($p['skills_summary'])<div class="kv">{{ $p['skills_summary'] }}</div>@endif
            <div class="kv">受信: {{ $p['received_at'] }}
                <a href="{{ $appUrl }}/matching/{{ $p['id'] }}">マッチング画面を開く →</a>
            </div>
            <div class="match-list">
                @foreach($p['matches'] as $m)
                    <div class="match-row">
                        <span class="score score-match">{{ $m['score'] }}</span>
                        <strong>{{ $m['name'] }}</strong>
                        @if($m['affiliation'])<span class="kv">　/　{{ $m['affiliation'] }}</span>@endif
                        @if($m['unit_price_max'])　<span class="kv">({{ $m['unit_price_max'] }}万)</span>@endif
                        @if($m['skills_summary'])<br><span class="kv" style="margin-left: 28px;">{{ $m['skills_summary'] }}</span>@endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
@endif

{{-- 有効と思われるメールリスト（技術者） --}}
@if(isset($sections['effective_engineer_mails']))
    @php $s = $sections['effective_engineer_mails']; @endphp
    <h2 class="h2-blue">👤 有効と思われるメールリスト（技術者）{{ $s['count'] }} 件　<span class="kv" style="font-weight:normal;">条件：過去3日 スコア70+</span></h2>
    @foreach($s['list'] as $e)
        <div class="parent-card">
            <div class="parent-head">
                <span class="score">⭐{{ $e['score'] }}</span>
                {{ $e['name'] }}
                @if($e['affiliation'])<span class="kv">　/　{{ $e['affiliation'] }}</span>@endif
                @if($e['unit_price_max'])　<span class="kv">({{ $e['unit_price_max'] }}万)</span>@endif
            </div>
            @if($e['skills_summary'])<div class="kv">{{ $e['skills_summary'] }}</div>@endif
            <div class="kv">受信: {{ $e['received_at'] }}
                <a href="{{ $appUrl }}/engineer-mails/{{ $e['id'] }}">マッチング画面を開く →</a>
            </div>
            <div class="match-list">
                @foreach($e['matches'] as $m)
                    <div class="match-row">
                        <span class="score score-match">{{ $m['score'] }}</span>
                        <strong>{{ $m['title'] }}</strong>
                        @if($m['customer_name'])<span class="kv">　/　{{ $m['customer_name'] }}</span>@endif
                        @if($m['unit_price_max'])　<span class="kv">({{ $m['unit_price_max'] }}万)</span>@endif
                        @if($m['skills_summary'])<br><span class="kv" style="margin-left: 28px;">{{ $m['skills_summary'] }}</span>@endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
@endif

{{-- 提案メール送信実績 --}}
@if(isset($sections['delivery']))
    @php $s = $sections['delivery']; @endphp
    <h2 class="h2-green">📤 {{ $data['target_date'] }} の提案メール送信（合計 {{ $s['count'] }} 件）</h2>
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
        @php $cls = $c['days_left'] <= 15 ? 'expire-soon' : 'expire-mid'; @endphp
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
