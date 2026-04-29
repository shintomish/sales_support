<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 2026-04-09 の一括RLS有効化migrationの後に追加されたテーブルに RLS を有効化する。
     * アプリは service_role キーで接続するため RLS をバイパスし、既存機能への影響はない。
     * anon / authenticated キーからの直接アクセスをブロックする。
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;

        $tables = [
            'mail_send_histories',
            'email_body_templates',
            'delivery_campaigns',
            'delivery_addresses',
            'delivery_send_histories',
            'delivery_address_state_snapshots',
        ];

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;

        $tables = [
            'mail_send_histories',
            'email_body_templates',
            'delivery_campaigns',
            'delivery_addresses',
            'delivery_send_histories',
            'delivery_address_state_snapshots',
        ];

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE \"{$table}\" DISABLE ROW LEVEL SECURITY");
        }
    }
};
