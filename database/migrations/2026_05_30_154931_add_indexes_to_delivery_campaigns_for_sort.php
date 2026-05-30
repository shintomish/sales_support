<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * delivery_campaigns のソート/絞り込みを支える複合 index を追加。
 *
 * 背景:
 *  - 一覧 (`DeliveryCampaignController::index`) はデフォルト `ORDER BY sent_at DESC` + WHERE tenant_id=?
 *    だが `(tenant_id, sent_at DESC)` を支える複合 index が不在で Sort + Seq Scan になる。
 *  - send_type で whereNotIn 5 値の絞り込みも頻発する (exclude_proposals)。
 *  - 月 240k 想定で線形劣化リスクのため CONCURRENTLY で追加 (本番アクセス止めず)。
 *
 * 参考: docs/720_paginate_speedup_roadmap_2026_05_30.md §High §/delivery-campaigns
 */
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_delivery_campaigns_tenant_sent_at '
            . 'ON public.delivery_campaigns (tenant_id, sent_at DESC)'
        );
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_delivery_campaigns_tenant_sendtype_sentat '
            . 'ON public.delivery_campaigns (tenant_id, send_type, sent_at DESC)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.idx_delivery_campaigns_tenant_sent_at');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.idx_delivery_campaigns_tenant_sendtype_sentat');
    }
};
