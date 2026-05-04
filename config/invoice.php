<?php

return [
    /*
     * 請求書PDFに表示するロゴファイルのパス（任意）
     * 設定なし or ファイルが存在しない場合はロゴを表示しない。
     * 画像は base64 データURIに埋め込まれるため、Browsershot のサンドボックスでも
     * ファイルシステム参照不要で描画できる。
     */
    'logo_path' => env('INVOICE_LOGO_PATH'),
];
