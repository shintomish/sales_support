@php
    /** @var \App\Models\FeedbackReport $feedback */
    $typeLabel = match ($feedback->type) {
        \App\Models\FeedbackReport::TYPE_BUG     => 'バグ',
        \App\Models\FeedbackReport::TYPE_REQUEST => '要望',
        default                                   => 'その他',
    };
    $badgeColor = match ($feedback->type) {
        \App\Models\FeedbackReport::TYPE_BUG     => '#dc2626',
        \App\Models\FeedbackReport::TYPE_REQUEST => '#2563eb',
        default                                   => '#6b7280',
    };
    $appUrl = rtrim(config('app.frontend_url') ?? config('app.url'), '/');
@endphp
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>社内フィードバック {{ $feedback->subject }}</title>
<style>
body { font-family: 'Hiragino Kaku Gothic ProN', 'Yu Gothic', 'Meiryo', sans-serif; font-size: 14px; color: #222; line-height: 1.6; max-width: 680px; margin: 0 auto; padding: 16px; }
h1 { font-size: 17px; margin: 0 0 16px 0; padding-bottom: 6px; border-bottom: 2px solid #2563eb; }
.badge { display: inline-block; padding: 2px 10px; border-radius: 999px; color: #fff; font-size: 12px; font-weight: bold; margin-right: 8px; }
.meta { background: #f9fafb; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
.meta dl { margin: 0; }
.meta dt { display: inline-block; width: 80px; color: #6b7280; font-size: 13px; }
.meta dd { display: inline; margin: 0; }
.meta dd::after { content: ''; display: block; height: 4px; }
.body { background: #fff; border: 1px solid #e5e7eb; padding: 14px 16px; border-radius: 6px; white-space: pre-wrap; word-break: break-word; }
.footer { margin-top: 20px; font-size: 12px; color: #6b7280; }
.footer a { color: #2563eb; }
</style>
</head>
<body>
<h1>
    <span class="badge" style="background: {{ $badgeColor }};">{{ $typeLabel }}</span>
    {{ $feedback->subject }}
</h1>

<div class="meta">
    <dl>
        <dt>テナント</dt><dd>{{ $tenantName ?? '(不明)' }}</dd>
        <dt>報告者</dt><dd>{{ $userName ?? '(不明)' }} &lt;{{ $userEmail ?? '-' }}&gt;</dd>
        <dt>画面URL</dt><dd>{{ $feedback->url ?: '-' }}</dd>
        <dt>UA</dt><dd style="font-size:12px; color:#6b7280;">{{ $feedback->user_agent ?: '-' }}</dd>
        <dt>登録日時</dt><dd>{{ optional($feedback->created_at)->copy()->setTimezone('Asia/Tokyo')->format('Y-m-d H:i:s') }}</dd>
    </dl>
</div>

<div class="body">{!! nl2br(e($feedback->body)) !!}</div>

<div class="footer">
    @if ($appUrl)
        管理画面: <a href="{{ $appUrl }}/settings/feedback/admin">{{ $appUrl }}/settings/feedback/admin</a>（super_admin）<br>
    @else
        管理画面: /settings/feedback/admin（super_admin）<br>
    @endif
    レコードID: {{ $feedback->id }}
</div>
</body>
</html>
