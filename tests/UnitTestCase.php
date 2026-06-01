<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * DB を一切触らない純粋な Unit テスト用の基底クラス。
 *
 * 通常の `Tests\TestCase` は `RefreshDatabase` trait を含むため、毎テストで
 * 全 migration が走る。本番で Pgsql 専用構文 (CONCURRENTLY / GIN / RLS 等) を
 * 使う migration は sqlite-in-memory で fail するため、HTTP/外部 API を mock
 * する Service の Unit テストでは migration を完全に回避したい。
 *
 * 使用例: ClaudeServiceTest (Http::fake のみ)
 */
abstract class UnitTestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__ . '/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }
}

