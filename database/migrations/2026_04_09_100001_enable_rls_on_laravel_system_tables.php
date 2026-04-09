<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Laravelフレームワーク標準テーブルにRLSを有効化する。
     * アプリは service_role キーで接続するため RLS をバイパスし、既存機能への影響はない。
     */
    public function up(): void
    {
        $tables = [
            'migrations',
            'password_reset_tokens',
            'sessions',
        ];

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'migrations',
            'password_reset_tokens',
            'sessions',
        ];

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE \"{$table}\" DISABLE ROW LEVEL SECURITY");
        }
    }
};
