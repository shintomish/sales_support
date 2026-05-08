<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>{{ $subject }}</title>
<style>
body {
    font-family: 'Hiragino Kaku Gothic ProN', 'Yu Gothic', 'Meiryo', sans-serif;
    font-size: 14px;
    color: #222;
    line-height: 1.6;
    max-width: 680px;
    margin: 0 auto;
    padding: 16px;
    word-break: break-word;
}
</style>
</head>
<body>{!! nl2br(e($body)) !!}</body>
</html>
