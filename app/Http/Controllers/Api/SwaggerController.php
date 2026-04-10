<?php

namespace App\Http\Controllers\Api;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Sales Support API',
    version: '1.0.0',
    description: 'SES企業向け営業支援システム API',
    contact: new OA\Contact(email: 'admin@example.com')
)]
#[OA\Server(url: '/', description: 'ローカル開発環境')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'Supabase Auth JWT トークン'
)]
#[OA\Tag(name: 'Auth', description: '認証')]
#[OA\Tag(name: 'Customers', description: '顧客管理')]
#[OA\Tag(name: 'Contacts', description: '担当者管理')]
#[OA\Tag(name: 'Deals', description: '案件管理')]
#[OA\Tag(name: 'Engineers', description: '技術者管理')]
#[OA\Tag(name: 'Activities', description: '活動履歴')]
#[OA\Tag(name: 'Emails', description: 'メール')]
#[OA\Tag(name: 'Matching', description: 'マッチング')]
#[OA\Tag(name: 'Tasks', description: 'タスク管理')]
#[OA\Tag(name: 'BusinessCards', description: '名刺管理')]
#[OA\Tag(name: 'Dashboard', description: 'ダッシュボード')]
class SwaggerController
{
}
