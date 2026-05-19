@php
    $sections = $data['sections'] ?? [];
    $appUrl   = rtrim(config('app.frontend_url') ?? config('app.url'), '/');
@endphp
============================================================
日次レポート ｜ {{ $data['target_date'] ?? '' }}
============================================================
要対応合計: {{ $data['action_total'] ?? 0 }} 件（有効と思われるメール + 期限切れ間近のSES契約）
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
@if(isset($sections['effective_project_mails']))

------------------------------------------------------------
[📨 有効と思われるメールリスト（案件） {{ $sections['effective_project_mails']['count'] }} 件]
@foreach($sections['effective_project_mails']['list'] as $p)
- ⭐{{ $p['score'] }} {{ $p['title'] }}{{ $p['customer_name'] ? ' / '.$p['customer_name'] : '' }}{{ $p['unit_price_max'] ? ' ('.$p['unit_price_max'].'万)' : '' }}
  受信: {{ $p['received_at'] }} | {{ $appUrl }}/matching/{{ $p['id'] }}
@foreach($p['matches'] as $m)
    └ マッチ{{ $m['score'] }} {{ $m['name'] }}{{ $m['affiliation'] ? ' / '.$m['affiliation'] : '' }}{{ $m['unit_price_max'] ? ' ('.$m['unit_price_max'].'万)' : '' }} {{ $m['skills_summary'] }}
@endforeach
@endforeach
@endif
@if(isset($sections['effective_engineer_mails']))

------------------------------------------------------------
[👤 有効と思われるメールリスト（技術者） {{ $sections['effective_engineer_mails']['count'] }} 件]
@foreach($sections['effective_engineer_mails']['list'] as $e)
- ⭐{{ $e['score'] }} {{ $e['name'] }}{{ $e['affiliation'] ? ' / '.$e['affiliation'] : '' }}{{ $e['unit_price_max'] ? ' ('.$e['unit_price_max'].'万)' : '' }} {{ $e['skills_summary'] }}
  受信: {{ $e['received_at'] }} | {{ $appUrl }}/engineer-mails/{{ $e['id'] }}
@foreach($e['matches'] as $m)
    └ マッチ{{ $m['score'] }} {{ $m['title'] }}{{ $m['customer_name'] ? ' / '.$m['customer_name'] : '' }}{{ $m['unit_price_max'] ? ' ('.$m['unit_price_max'].'万)' : '' }} {{ $m['skills_summary'] }}
@endforeach
@endforeach
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
