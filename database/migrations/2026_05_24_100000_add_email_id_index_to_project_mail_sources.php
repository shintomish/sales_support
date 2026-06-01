<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * project_mail_sources.email_id に btree index を追加。
 *
 * Sentry 週次 (2026-05-23) で score-project-mails の遅延 (11.58s) を観測。
 * EXPLAIN ANALYZE で whereNotExists の anti-join が project_mail_sources を Seq Scan
 * (24,489 rows / 380ms) していることが判明。email_id に index が無いため hash join 用に
 * 全件読みが発生していた。engineer_mail_sources には既に同等の index が存在する。
 *
 * 設計判断:
 * - UNIQUE 化したいところだが 2026-05-24 時点で 23 件の重複 email_id があるため non-unique btree。
 *   重複解消後に別 migration で UNIQUE 化する。
 * - 24,489 行 + 同時書き込みを止めないため CONCURRENTLY を使用。
 *   CONCURRENTLY は transaction 内で実行できないため $withinTransaction = false。
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS project_mail_sources_email_id_index ON public.project_mail_sources USING btree (email_id)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.project_mail_sources_email_id_index');
    }
};
