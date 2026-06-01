<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * delivery_send_histories の返信紐づけクエリ用 partial index を追加。
 *
 * 背景:
 *   KagoyaMailService::storeRawMessage の返信紐づけは以下 3 経路で DSH を引く:
 *     1. ses_message_id 完全一致
 *     2. ses_message_id LIKE '%...%' フォールバック
 *     3. email + status='sent' + campaign.subject like で最新探索
 *
 *   現状 52k 行で ses_message_id にも email にも index 無し → Seq Scan。
 *   毎メール取込時に走るため Disk IO を圧迫していた (docs/730 #32)。
 *
 *   また同時に KagoyaMailService 側で tenant_id WHERE 条件を明示追加するため、
 *   tenant_id を先頭にした partial index で「未紐づけ行のみ」狙い撃ち。
 *
 *   なお LIKE '%...%' (leading wildcard) は btree では使えないので別件 (GIN trgm 等)。
 *   tenant_id で絞った後の行数は十分小さくなる想定で当面 fallback パスのまま許容。
 *
 * 本番テーブルは 52k 行・無停止のため CONCURRENTLY で追加。
 */
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        // 経路 1: ses_message_id 完全一致 (in-reply-to 取得時)
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_dsh_tenant_ses_message_id_unlinked '
            . 'ON public.delivery_send_histories (tenant_id, ses_message_id) '
            . 'WHERE reply_email_id IS NULL'
        );

        // 経路 3: email + status='sent' フォールバック
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_dsh_tenant_email_status_unlinked '
            . 'ON public.delivery_send_histories (tenant_id, email, status) '
            . "WHERE reply_email_id IS NULL"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.idx_dsh_tenant_ses_message_id_unlinked');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.idx_dsh_tenant_email_status_unlinked');
    }
};
