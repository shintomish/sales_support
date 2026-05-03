<?php

namespace Tests\Pgsql;

use Illuminate\Foundation\Application;
use Tests\TestCase;

/**
 * PostgreSQL 固有の構文（ilike / ::text キャスト / || 文字列結合 / COALESCE 等）を
 * 検証するための抽象テストケース。
 *
 * docker compose の test-postgres コンテナを利用する。コンテナ未起動の場合は
 * setUp 内でテストをスキップする。
 *
 * 接続定義は createApplication 内で inline 注入し、config/database.php は汚染しない。
 */
abstract class PgsqlTestCase extends TestCase
{
    private const PGSQL_TEST_CONNECTION = 'pgsql_test';

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $app['config']->set('database.default', self::PGSQL_TEST_CONNECTION);
        $app['config']->set('database.connections.' . self::PGSQL_TEST_CONNECTION, [
            'driver'   => 'pgsql',
            'host'     => env('TEST_PGSQL_HOST', 'test-postgres'),
            'port'     => env('TEST_PGSQL_PORT', '5432'),
            'database' => env('TEST_PGSQL_DATABASE', 'sales_support_test'),
            'username' => env('TEST_PGSQL_USERNAME', 'laravel'),
            'password' => env('TEST_PGSQL_PASSWORD', 'password'),
            'charset'  => 'utf8',
            'prefix'   => '',
            'schema'   => 'public',
            'sslmode'  => 'prefer',
        ]);

        return $app;
    }

    /** PG 接続可否（テストクラス間で初回のみ実接続して以降はキャッシュ） */
    private static ?bool $pgsqlAvailable = null;

    protected function setUp(): void
    {
        if (self::$pgsqlAvailable === null) {
            try {
                $pdo = new \PDO(
                    sprintf(
                        'pgsql:host=%s;port=%s;dbname=%s',
                        env('TEST_PGSQL_HOST', 'test-postgres'),
                        env('TEST_PGSQL_PORT', '5432'),
                        env('TEST_PGSQL_DATABASE', 'sales_support_test'),
                    ),
                    env('TEST_PGSQL_USERNAME', 'laravel'),
                    env('TEST_PGSQL_PASSWORD', 'password'),
                    [\PDO::ATTR_TIMEOUT => 5],
                );
                $pdo = null;
                self::$pgsqlAvailable = true;
            } catch (\Throwable $e) {
                self::$pgsqlAvailable = false;
            }
        }

        if (!self::$pgsqlAvailable) {
            $this->markTestSkipped('PostgreSQL test database is unavailable. Run "docker compose up -d test-postgres" first.');
            return;
        }

        parent::setUp();
    }
}
