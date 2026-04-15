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
    </style>
</head>
<body>
<div class="box">
    @if ($status === 'success')
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
