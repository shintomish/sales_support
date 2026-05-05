@php
    $sections = $data['sections'] ?? [];
    $appUrl   = rtrim(config('app.frontend_url') ?? config('app.url'), '/');
@endphp
============================================================
日次レポート ｜ {{ $data['target_date'] ?? '' }}
============================================================
要対応件数: {{ $data['total_action_items'] ?? 0 }} 件
@if(!empty($data['ai_summary']))

------------------------------------------------------------
[🤖 今日のアクション（AI 提案）]
{{ $data['ai_summary'] }}
@endif
@if(isset($sections['inbox']))

------------------------------------------------------------
[📬 昨日の受信メール（合計 {{ $sections['inbox']['count'] }} 件）]
- 技術者紹介: {{ $sections['inbox']['engineer'] }} 件
- 案件紹介:   {{ $sections['inbox']['project'] }} 件
- その他:     {{ $sections['inbox']['other'] }} 件
@endif
@if(isset($sections['matches']))

------------------------------------------------------------
[🔵 新規SES候補（要確認） {{ $sections['matches']['count'] }} 件]
@foreach($sections['matches']['top'] as $m)
- スコア{{ $m['score'] }} {{ $m['name'] }}{{ $m['unit_price_max'] ? ' ('.$m['unit_price_max'].'万)' : '' }} {{ $m['skills_summary'] }}
  受信: {{ $m['received_at'] }} | {{ $appUrl }}/engineer-mail-sources/{{ $m['id'] }}
@endforeach
@if($sections['matches']['count'] > count($sections['matches']['top']))
…他 {{ $sections['matches']['count'] - count($sections['matches']['top']) }} 件
@endif
@endif
@if(isset($sections['delivery']))

------------------------------------------------------------
[📤 昨日の提案メール送信（合計 {{ $sections['delivery']['count'] }} 件）]
- 送信成功: {{ $sections['delivery']['sent'] }} 件
@if($sections['delivery']['failed']  > 0)- 送信失敗: {{ $sections['delivery']['failed'] }} 件@endif
@if($sections['delivery']['replied'] > 0)- 返信受信: {{ $sections['delivery']['replied'] }} 件@endif
@endif
@if(isset($sections['expiring']))

------------------------------------------------------------
[🟡 期限切れ間近のSES契約（30日以内 {{ $sections['expiring']['count'] }} 件）]
@foreach($sections['expiring']['list'] as $c)
- あと{{ $c['days_left'] }}日 ({{ $c['end_date'] }}) {{ $c['engineer_name'] }}{{ $c['customer_name'] ? ' / '.$c['customer_name'] : '' }}{{ $c['deal_title'] ? ' / '.$c['deal_title'] : '' }}
@endforeach
@endif
@if(empty($sections))

本日特筆すべき動きはありませんでした。
@endif

------------------------------------------------------------
配信停止: {{ $appUrl }}/settings/report-recipients
送信元: 株式会社アイゼン・ソリューション 営業支援システム
