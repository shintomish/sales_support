@php
    $sections = $data['sections'] ?? [];
    $appUrl   = rtrim(config('app.frontend_url') ?? config('app.url'), '/');
@endphp
============================================================
日次レポート ｜ {{ $data['target_date'] ?? '' }}
============================================================
要対応合計: {{ $data['action_total'] ?? 0 }} 件（新着スコア70+ + 期限切れ間近のSES契約）
@if(!empty($data['ai_summary']))

------------------------------------------------------------
[🤖 今日のアクション（AI 提案）]
{{ $data['ai_summary'] }}
@endif
@if(isset($sections['inbox']))

------------------------------------------------------------
[📬 受信メール（{{ $data['target_date'] }} 分　合計 {{ $sections['inbox']['count'] }} 件）]
- 技術者紹介: {{ $sections['inbox']['engineer'] }} 件
- 案件紹介:   {{ $sections['inbox']['project'] }} 件
- その他:     {{ $sections['inbox']['other'] }} 件
@endif
@if(isset($sections['engineer_matches']))

------------------------------------------------------------
[👤 新着技術者（スコア70+ 直近24h） {{ $sections['engineer_matches']['count'] }} 件]
@foreach($sections['engineer_matches']['top'] as $m)
- スコア{{ $m['score'] }} {{ $m['title'] }}{{ $m['unit_price_max'] ? ' ('.$m['unit_price_max'].'万)' : '' }} {{ $m['skills_summary'] }}
  受信: {{ $m['received_at'] }} | {{ $appUrl }}/engineer-mails?select={{ $m['id'] }}
@endforeach
@if($sections['engineer_matches']['count'] > count($sections['engineer_matches']['top']))
…他 {{ $sections['engineer_matches']['count'] - count($sections['engineer_matches']['top']) }} 件
@endif
@endif
@if(isset($sections['project_matches']))

------------------------------------------------------------
[📨 新着案件（スコア70+ 直近24h） {{ $sections['project_matches']['count'] }} 件]
@foreach($sections['project_matches']['top'] as $m)
- スコア{{ $m['score'] }} {{ $m['title'] }}{{ $m['sub'] ? ' / '.$m['sub'] : '' }}{{ $m['unit_price_max'] ? ' ('.$m['unit_price_max'].'万)' : '' }} {{ $m['skills_summary'] }}
  受信: {{ $m['received_at'] }} | {{ $appUrl }}/project-mails?select={{ $m['id'] }}
@endforeach
@if($sections['project_matches']['count'] > count($sections['project_matches']['top']))
…他 {{ $sections['project_matches']['count'] - count($sections['project_matches']['top']) }} 件
@endif
@endif
@if(isset($sections['delivery']))

------------------------------------------------------------
[📤 {{ $data['target_date'] }} の提案メール送信（合計 {{ $sections['delivery']['count'] }} 件）]
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
