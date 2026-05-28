<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * engineer_mail_sources の autovacuum 閾値をさらに引き下げる (0.05 → 0.02)。
 *
 * 背景:
 *   2026-05-17 で 0.05 に設定済 (`2026_05_17_130000_tune_autovacuum_for_email_tables`)。
 *   その後 64,477 行 × 0.05 = 3,224 件 insert 毎の vacuum 発火に。
 *   実測 (5/28) で score-engineer-mails の EXPLAIN ANALYZE に Heap Fetches=3,334 が出現し
 *   anti-join に +70ms 上乗せ。発火閾値直前で蓄積する時間帯が Sentry slow query の発生源。
 *
 *   0.02 に下げると 1,290 件 insert 毎の発火 = 約 1/2.5 の蓄積で済む。
 *   1日あたり vacuum 発火が 1回 → 約 3〜4回に増えるが、insert-only な視認性マップ更新のみで
 *   テーブルサイズ 64k 行クラスでは IO 負荷無視可能。
 *
 * 対象は engineer_mail_sources のみ。emails / project_mail_sources は実測の HF 蓄積が
 * 顕在化していないため現状 0.05 のまま据え置き。
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE public.engineer_mail_sources SET ('
            . 'autovacuum_vacuum_insert_scale_factor = 0.02, '
            . 'autovacuum_analyze_scale_factor = 0.02'
            . ')'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE public.engineer_mail_sources SET ('
            . 'autovacuum_vacuum_insert_scale_factor = 0.05, '
            . 'autovacuum_analyze_scale_factor = 0.05'
            . ')'
        );
    }
};
