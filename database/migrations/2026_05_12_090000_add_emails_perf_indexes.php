<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Disk IO 高騰対策インデックス追加。
 *
 * 本番(smzoqpvaxznqcwrsgjju) の pg_stat_statements で
 *   - emails WHERE category=? AND NOT EXISTS (engineer_mail_sources/project_mail_sources)
 *   - COUNT(*) FROM emails WHERE is_read=?
 *   - emails WHERE category IS NULL ORDER BY received_at DESC
 * が tenant_id 絞り込みなしで発火し、既存の (tenant_id, ...) インデックスが
 * 使えずフルスキャンしていた。category 単独・is_read 単独で引けるインデックスを追加する。
 *
 * CREATE INDEX CONCURRENTLY を使うためトランザクションを無効化。
 */
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS emails_category_received_at_idx ON public.emails (category, received_at DESC)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS emails_is_read_idx ON public.emails (is_read)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.emails_category_received_at_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.emails_is_read_idx');
    }
};
