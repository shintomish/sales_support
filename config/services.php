<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        // 2026-06-15 9AM PT に claude-sonnet-4-20250514 retire のため Sonnet 4.6 に移行。
        // .env CLAUDE_MODEL で上書き可能。
        'model'   => env('CLAUDE_MODEL', 'claude-sonnet-4-6'),
        // 軽量タスク (汎用 ask / 提案メール下書き) 用の安価モデル。
        // .env CLAUDE_HAIKU_MODEL で上書き可能 (docs/730 #14)。
        'haiku_model' => env('CLAUDE_HAIKU_MODEL', 'claude-haiku-4-5-20251001'),

        // Refinitiv 注文書 PDF 抽出専用モデル。
        // 2026-05-29 に Opus 4.8 を試用したが、同一 PO で全14フィールド完全一致のため
        // コスト/レイテンシで優位な Sonnet 4.6 に戻す。Refinitiv の PDF フォーマットは
        // 安定しているため Sonnet で十分な精度が出る。
        // 必要時は .env CLAUDE_REFINITIV_MODEL=claude-opus-4-8 で上書き可。
        'refinitiv_model' => env('CLAUDE_REFINITIV_MODEL', 'claude-sonnet-4-6'),

        // 要件マッチング (docs/480) で 1 リクエストあたり生成する match result の上限。
        // 鮮度マッチで上位 N 件を一括判定する場合のコストガード。
        'requirement_match_max_per_request' => env('CLAUDE_REQUIREMENT_MATCH_MAX_PER_REQUEST', 5),
    ],

    'google_vision' => [
        'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS', storage_path('credentials/google-vision.json')),
        'project_id'  => env('GOOGLE_CLOUD_PROJECT_ID'),
        'secret_name' => env('GOOGLE_SECRET_NAME', 'vision-api-credentials'),
    ],


    'supabase' => [
        'url'              => env('SUPABASE_URL'),
        'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
        'bucket'           => env('SUPABASE_BUCKET', 'business-cards'),
        'jwks_url'         => env('SUPABASE_JWKS_URL'),
    ],

    'gmail' => [
        'client_id'     => env('GMAIL_CLIENT_ID'),
        'client_secret' => env('GMAIL_CLIENT_SECRET'),
        'redirect_uri'  => env('GMAIL_REDIRECT_URI', 'http://localhost:8090/api/v1/gmail/callback'),
    ],

    'puppeteer' => [
        'cache_dir'       => env('PUPPETEER_CACHE_DIR', '/opt/puppeteer-cache'),
        'executable_path' => env('PUPPETEER_EXECUTABLE_PATH'),
    ],

    'kagoya_pop3' => [
        'host'     => env('KAGOYA_POP3_HOST'),
        'username' => env('KAGOYA_POP3_USERNAME'),
        'password' => env('KAGOYA_POP3_PASSWORD'),
    ],
];
