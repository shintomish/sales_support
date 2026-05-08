<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>配信停止 - 株式会社アイゼン・ソリューション</title>
    <style>
        body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f5f5f5; }
        .box { background: #fff; border-radius: 8px; padding: 40px; max-width: 480px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { font-size: 1.2rem; margin-bottom: 16px; }
        p { color: #555; line-height: 1.6; }
        .actions { margin-top: 28px; display: flex; gap: 12px; justify-content: center; }
        .btn { display: inline-block; border: none; cursor: pointer; padding: 10px 24px; border-radius: 6px; font-size: 0.95rem; font-family: inherit; text-decoration: none; }
        .btn-yes { background: #dc2626; color: #fff; }
        .btn-yes:hover { background: #b91c1c; }
        .btn-no { background: #e5e7eb; color: #374151; }
        .btn-no:hover { background: #d1d5db; }
    </style>
</head>
<body>
<div class="box">
    @if ($status === 'confirm')
        <h1>配信を停止しますか？</h1>
        <p>「はい」を選ぶと、ご登録のメールアドレスへの配信を停止します。<br>この操作は後から元に戻せません。</p>
        <div class="actions">
            <form method="POST" action="{{ route('unsubscribe.confirm', ['token' => $token]) }}" style="margin:0;">
                @csrf
                <button type="submit" class="btn btn-yes">はい、停止する</button>
            </form>
            <a href="about:blank" onclick="window.close(); return false;" class="btn btn-no">いいえ</a>
        </div>
    @elseif ($status === 'success')
        <h1>配信停止が完了しました</h1>
        <p>ご登録のメールアドレスへの配信を停止いたしました。<br>今後このアドレスへのメール送信は行いません。</p>
    @elseif ($status === 'already')
        <h1>すでに配信停止済みです</h1>
        <p>このメールアドレスはすでに配信停止の手続きが完了しています。</p>
    @else
        <h1>無効なリンクです</h1>
        <p>このリンクは無効または期限切れです。</p>
    @endif
    <p style="margin-top: 24px; font-size: 0.85rem; color: #999;">株式会社アイゼン・ソリューション</p>
</div>
</body>
</html>
