<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/emails/unread-count の応答時間改善。
 *
 * 既存 (tenant_id, is_read) インデックスは Index Only Scan が選ばれるものの、
 * 本番計測で Heap Fetches: 4498/6600 が発生し COUNT が 150ms 程度。
 * 未読行のみを格納する partial index に切り替えると、サイズが激減し
 * 可視性マップ更新の影響も受けにくくなる (期待: 5-10ms)。
 *
 * CREATE INDEX CONCURRENTLY を使うためトランザクションを無効化。
 */
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS emails_tenant_unread_partial_idx '
            . 'ON public.emails (tenant_id) WHERE is_read = false'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.emails_tenant_unread_partial_idx');
    }
};
