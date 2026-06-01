<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * emails / engineer_mail_sources / project_mail_sources の autovacuum 閾値を引き下げる。
 *
 * 背景:
 * Sentry で score-engineer-mails / unread-count が断続的に slow query 化。
 * 原因は autovacuum 発火後 1-2日間で大量 insert が蓄積し、Index Only Scan が
 * Heap Fetches を多発させていたこと。デフォルト
 *   autovacuum_vacuum_insert_scale_factor = 0.2   (= 53k 行で 11,645 件 insert 毎)
 *   autovacuum_analyze_scale_factor       = 0.1
 *   autovacuum_vacuum_scale_factor        = 0.2   (dead tuple 用)
 * を 0.05 に引き下げて 4倍頻度で visibility map を更新する。
 *
 * 影響:
 *   - I/O は微増 (1日あたり数回 → 十数回 程度)
 *   - emails 53k 行クラスでは負荷無視可能
 *   - Heap Fetches 蓄積を恒常的に低位に保てる
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement(
            'ALTER TABLE public.emails SET ('
            . 'autovacuum_vacuum_insert_scale_factor = 0.05, '
            . 'autovacuum_analyze_scale_factor = 0.05, '
            . 'autovacuum_vacuum_scale_factor = 0.05'
            . ')'
        );
        DB::statement(
            'ALTER TABLE public.engineer_mail_sources SET ('
            . 'autovacuum_vacuum_insert_scale_factor = 0.05, '
            . 'autovacuum_analyze_scale_factor = 0.05'
            . ')'
        );
        DB::statement(
            'ALTER TABLE public.project_mail_sources SET ('
            . 'autovacuum_vacuum_insert_scale_factor = 0.05, '
            . 'autovacuum_analyze_scale_factor = 0.05'
            . ')'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement(
            'ALTER TABLE public.emails RESET ('
            . 'autovacuum_vacuum_insert_scale_factor, '
            . 'autovacuum_analyze_scale_factor, '
            . 'autovacuum_vacuum_scale_factor'
            . ')'
        );
        DB::statement(
            'ALTER TABLE public.engineer_mail_sources RESET ('
            . 'autovacuum_vacuum_insert_scale_factor, '
            . 'autovacuum_analyze_scale_factor'
            . ')'
        );
        DB::statement(
            'ALTER TABLE public.project_mail_sources RESET ('
            . 'autovacuum_vacuum_insert_scale_factor, '
            . 'autovacuum_analyze_scale_factor'
            . ')'
        );
    }
};
