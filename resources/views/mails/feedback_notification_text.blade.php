@php
    $typeLabel = match ($feedback->type) {
        \App\Models\FeedbackReport::TYPE_BUG     => 'バグ',
        \App\Models\FeedbackReport::TYPE_REQUEST => '要望',
        default                                   => 'その他',
    };
@endphp
社内ユーザーから {{ $typeLabel }}が報告されました。

──────────────────────────────────
種別     : {{ $typeLabel }}
件名     : {{ $feedback->subject }}
テナント : {{ $tenantName ?? '(不明)' }}
報告者   : {{ $userName ?? '(不明)' }} <{{ $userEmail ?? '-' }}>
画面URL  : {{ $feedback->url ?: '-' }}
UA       : {{ $feedback->user_agent ?: '-' }}
登録日時 : {{ optional($feedback->created_at)->copy()->setTimezone('Asia/Tokyo')->format('Y-m-d H:i:s') }}
──────────────────────────────────

【内容】
{{ $feedback->body }}

──────────────────────────────────
管理画面: /settings/feedback/admin (super_admin)
レコードID: {{ $feedback->id }}
