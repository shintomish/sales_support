<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/notifications 用の複合 index を追加 (future-proof)。
 *
 * 現状 tasks=0行 / invoices=7行で Seq Scan でも 0.1ms だが、
 * NotificationController が dashboard ポーリングで高頻度に叩かれるエンドポイントなので
 * 帳票/タスクの増加 (見積/注文/請求の月次蓄積 + タスク機能本格運用) に備える。
 *
 * いずれも tenant_id 先頭 + 既存 invoices 系 index の命名パターンを踏襲。
 * 本番アクセスを止めないため CONCURRENTLY で追加 ([[feedback_concurrently_index_swap]])。
 */
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        // 期限切れタスク (NotificationController::index の overdueTasks)
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_tasks_tenant_status_due '
            . 'ON public.tasks (tenant_id, status, due_date)'
        );

        // 承認待ち / 却下一覧 (pending_approvals / rejected_invoices)
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_invoices_tenant_approval_updated '
            . 'ON public.invoices (tenant_id, approval_status, updated_at DESC)'
        );

        // 自分の直近承認済 (recently_approved)
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_invoices_tenant_submittedby_approvedat '
            . 'ON public.invoices (tenant_id, submitted_by, approved_at DESC)'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.idx_tasks_tenant_status_due');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.idx_invoices_tenant_approval_updated');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.idx_invoices_tenant_submittedby_approvedat');
    }
};
