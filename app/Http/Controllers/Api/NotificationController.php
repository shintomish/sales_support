<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceNotificationRead;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        $overdueTasks = Task::with('customer')
            ->where('status', '!=', '完了')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->orderBy('due_date')
            ->get()
            ->map(fn($t) => [
                'id'           => $t->id,
                'title'        => $t->title,
                'priority'     => $t->priority ?? '低',
                'due_date'     => $t->due_date->toDateString(),
                'customer'     => $t->customer ? ['company_name' => $t->customer->company_name] : null,
            ]);

        $user = Auth::user();
        $isAdmin = $user && in_array($user->role ?? null, ['tenant_admin', 'super_admin'], true);

        // 承認待ち（管理者以上のみ取得）。一般メンバーには空配列を返す
        $pendingApprovals = collect();
        if ($isAdmin) {
            $pendingApprovals = Invoice::with('customer')
                ->where('approval_status', 'pending')
                ->orderBy('updated_at', 'desc')
                ->limit(20)
                ->get()
                ->map(fn($i) => [
                    'id'             => $i->id,
                    'invoice_number' => $i->invoice_number,
                    'doc_type'       => $i->doc_type,
                    'total'          => (int) $i->total,
                    'customer'       => $i->customer ? ['company_name' => $i->customer->company_name] : null,
                    'updated_at'     => optional($i->updated_at)->toIso8601String(),
                ]);
        }

        // 既読化済みの invoice_id を type ごとに収集（一般メンバーのみ参照）
        $readIds = [
            'rejected' => collect(),
            'approved' => collect(),
        ];
        if ($user && ! $isAdmin) {
            $readIds['rejected'] = InvoiceNotificationRead::query()
                ->where('user_id', $user->id)
                ->where('notification_type', 'rejected')
                ->pluck('invoice_id');
            $readIds['approved'] = InvoiceNotificationRead::query()
                ->where('user_id', $user->id)
                ->where('notification_type', 'approved')
                ->pluck('invoice_id');
        }

        // 却下された請求書（一般メンバー向け）。管理者は承認側なので不要
        $rejectedInvoices = collect();
        if ($user && ! $isAdmin) {
            $rejectedInvoices = Invoice::with('customer')
                ->where('approval_status', 'rejected')
                ->whereNotIn('id', $readIds['rejected'])
                ->orderBy('updated_at', 'desc')
                ->limit(20)
                ->get()
                ->map(fn($i) => [
                    'id'             => $i->id,
                    'invoice_number' => $i->invoice_number,
                    'doc_type'       => $i->doc_type,
                    'total'          => (int) $i->total,
                    'customer'       => $i->customer ? ['company_name' => $i->customer->company_name] : null,
                    'approval_comment' => $i->approval_comment,
                    'updated_at'     => optional($i->updated_at)->toIso8601String(),
                ]);
        }

        // 直近で承認された自身の申請（一般メンバー向け・7日以内）
        $recentlyApproved = collect();
        if ($user && ! $isAdmin) {
            $recentlyApproved = Invoice::with('customer')
                ->where('approval_status', 'approved')
                ->where('submitted_by', $user->id)
                ->where('approved_at', '>=', $today->copy()->subDays(7))
                ->whereNotIn('id', $readIds['approved'])
                ->orderBy('approved_at', 'desc')
                ->limit(20)
                ->get()
                ->map(fn($i) => [
                    'id'             => $i->id,
                    'invoice_number' => $i->invoice_number,
                    'doc_type'       => $i->doc_type,
                    'total'          => (int) $i->total,
                    'customer'       => $i->customer ? ['company_name' => $i->customer->company_name] : null,
                    'approved_at'    => optional($i->approved_at)->toIso8601String(),
                ]);
        }

        return response()->json([
            'overdue_tasks'              => $overdueTasks,
            'overdue_tasks_count'        => $overdueTasks->count(),
            'pending_approvals'          => $pendingApprovals,
            'pending_approvals_count'    => $pendingApprovals->count(),
            'rejected_invoices'          => $rejectedInvoices,
            'rejected_invoices_count'    => $rejectedInvoices->count(),
            'recently_approved'          => $recentlyApproved,
            'recently_approved_count'    => $recentlyApproved->count(),
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
