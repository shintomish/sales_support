<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index()
    {
        $user     = Auth::user();
        $isAdmin  = $user && in_array($user->role ?? null, ['tenant_admin', 'super_admin'], true);
        $tenantId = $user?->tenant_id ?? 0;
        $userId   = $user?->id ?? 0;

        // Session Pooler 経由の round-trip を 5-7 回 → 1 回に集約 (806ms → ~150ms 目標)。
        // 4 つの CTE で overdue/pending/rejected/recently_approved を構築し、最後に jsonb_agg。
        // tenant_id フィルタは GlobalScope に頼らず明示的に WHERE で必ず付与する。
        // bindings は ? 順番依存なので追加時は SQL とリスト両方を同期する。
        $sql = <<<'SQL'
            WITH
              read_rej AS (
                SELECT invoice_id FROM invoice_notification_reads
                WHERE user_id = ? AND notification_type = 'rejected'
              ),
              read_app AS (
                SELECT invoice_id FROM invoice_notification_reads
                WHERE user_id = ? AND notification_type = 'approved'
              ),
              overdue AS (
                SELECT t.id, t.title,
                       COALESCE(t.priority, '低') AS priority,
                       t.due_date::text AS due_date,
                       CASE WHEN c.id IS NOT NULL
                            THEN jsonb_build_object('company_name', c.company_name)
                            ELSE NULL END AS customer
                FROM tasks t
                LEFT JOIN customers c ON c.id = t.customer_id
                WHERE t.tenant_id = ?
                  AND t.status <> '完了'
                  AND t.due_date IS NOT NULL
                  AND t.due_date < CURRENT_DATE
                ORDER BY t.due_date
              ),
              pending AS (
                -- admin のみ: ? に $isAdmin を bind → false なら WHERE 全体が FALSE で空
                SELECT i.id, i.invoice_number, i.doc_type, i.total::bigint AS total,
                       to_char(i.updated_at, 'YYYY-MM-DD"T"HH24:MI:SS+00:00') AS updated_at,
                       CASE WHEN c.id IS NOT NULL
                            THEN jsonb_build_object('company_name', c.company_name)
                            ELSE NULL END AS customer
                FROM invoices i
                LEFT JOIN customers c ON c.id = i.customer_id
                WHERE ?
                  AND i.tenant_id = ?
                  AND i.approval_status = 'pending'
                ORDER BY i.updated_at DESC
                LIMIT 20
              ),
              rejected AS (
                -- non-admin のみ: ? に !$isAdmin を bind
                SELECT i.id, i.invoice_number, i.doc_type, i.total::bigint AS total,
                       to_char(i.updated_at, 'YYYY-MM-DD"T"HH24:MI:SS+00:00') AS updated_at, i.approval_comment,
                       CASE WHEN c.id IS NOT NULL
                            THEN jsonb_build_object('company_name', c.company_name)
                            ELSE NULL END AS customer
                FROM invoices i
                LEFT JOIN customers c ON c.id = i.customer_id
                WHERE ?
                  AND i.tenant_id = ?
                  AND i.approval_status = 'rejected'
                  AND NOT EXISTS (SELECT 1 FROM read_rej r WHERE r.invoice_id = i.id)
                ORDER BY i.updated_at DESC
                LIMIT 20
              ),
              approved AS (
                -- non-admin のみ: ? に !$isAdmin を bind
                SELECT i.id, i.invoice_number, i.doc_type, i.total::bigint AS total,
                       to_char(i.approved_at, 'YYYY-MM-DD"T"HH24:MI:SS+00:00') AS approved_at,
                       CASE WHEN c.id IS NOT NULL
                            THEN jsonb_build_object('company_name', c.company_name)
                            ELSE NULL END AS customer
                FROM invoices i
                LEFT JOIN customers c ON c.id = i.customer_id
                WHERE ?
                  AND i.tenant_id = ?
                  AND i.approval_status = 'approved'
                  AND i.submitted_by = ?
                  AND i.approved_at >= (CURRENT_DATE - INTERVAL '7 days')
                  AND NOT EXISTS (SELECT 1 FROM read_app r WHERE r.invoice_id = i.id)
                ORDER BY i.approved_at DESC
                LIMIT 20
              )
            SELECT
              COALESCE((SELECT jsonb_agg(to_jsonb(o) ORDER BY o.due_date)        FROM overdue  o), '[]'::jsonb) AS overdue_tasks,
              COALESCE((SELECT jsonb_agg(to_jsonb(p) ORDER BY p.updated_at DESC) FROM pending  p), '[]'::jsonb) AS pending_approvals,
              COALESCE((SELECT jsonb_agg(to_jsonb(r) ORDER BY r.updated_at DESC) FROM rejected r), '[]'::jsonb) AS rejected_invoices,
              COALESCE((SELECT jsonb_agg(to_jsonb(a) ORDER BY a.approved_at DESC) FROM approved a), '[]'::jsonb) AS recently_approved
            SQL;

        $row = DB::selectOne($sql, [
            $userId,            // read_rej.user_id
            $userId,            // read_app.user_id
            $tenantId,          // overdue.tenant_id
            $isAdmin,           // pending: admin only
            $tenantId,          // pending.tenant_id
            !$isAdmin,          // rejected: non-admin only
            $tenantId,          // rejected.tenant_id
            !$isAdmin,          // approved: non-admin only
            $tenantId,          // approved.tenant_id
            $userId,            // approved.submitted_by
        ]);

        $overdue  = json_decode($row->overdue_tasks     ?? '[]', true) ?: [];
        $pending  = json_decode($row->pending_approvals ?? '[]', true) ?: [];
        $rejected = json_decode($row->rejected_invoices ?? '[]', true) ?: [];
        $approved = json_decode($row->recently_approved ?? '[]', true) ?: [];

        return response()->json([
            'overdue_tasks'              => $overdue,
            'overdue_tasks_count'        => count($overdue),
            'pending_approvals'          => $pending,
            'pending_approvals_count'    => count($pending),
            'rejected_invoices'          => $rejected,
            'rejected_invoices_count'    => count($rejected),
            'recently_approved'          => $approved,
            'recently_approved_count'    => count($approved),
        ]);
    }

    /**
     * POST /api/v1/notifications/mark-read
     *
     * 承認系通知（approved / rejected）を user 単位で既読化する。
     * - type='approved' なら自身が申請した直近7日の承認済み（recently_approved 相当）を既読化
     * - type='rejected' なら自テナントの却下分を既読化
     * doc_type を渡すと該当帳票種類に限定可能。
     * 単発で消したい場合は invoice_ids を渡す（既読化対象を限定）。
     */
    public function markRead(Request $request): JsonResponse
    {
        $v = $request->validate([
            'type'          => ['required', 'in:approved,rejected'],
            'doc_type'      => ['nullable', 'in:invoice,estimate,purchase_order'],
            'invoice_ids'   => ['nullable', 'array'],
            'invoice_ids.*' => ['integer'],
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => '認証が必要です'], 401);
        }

        $today = Carbon::today();

        $invoiceQuery = Invoice::query()
            ->when($v['type'] === 'approved', fn ($q) => $q
                ->where('approval_status', 'approved')
                ->where('submitted_by', $user->id)
                ->where('approved_at', '>=', $today->copy()->subDays(7)))
            ->when($v['type'] === 'rejected', fn ($q) => $q
                ->where('approval_status', 'rejected'))
            ->when(!empty($v['doc_type']), fn ($q) => $q->where('doc_type', $v['doc_type']))
            ->when(!empty($v['invoice_ids']), fn ($q) => $q->whereIn('id', $v['invoice_ids']));

        $targetIds = $invoiceQuery->pluck('id');

        $now = now();
        $rows = $targetIds->map(fn ($invoiceId) => [
            'tenant_id'         => $user->tenant_id,
            'invoice_id'        => $invoiceId,
            'user_id'           => $user->id,
            'notification_type' => $v['type'],
            'read_at'           => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ])->all();

        if (!empty($rows)) {
            DB::table('invoice_notification_reads')->upsert(
                $rows,
                ['invoice_id', 'user_id', 'notification_type'],
                ['read_at', 'updated_at'],
            );
        }

        return response()->json([
            'marked_count' => count($rows),
        ]);
    }
}
