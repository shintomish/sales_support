<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

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
                    'doc_type'       => 'invoice',
                    'total'          => (int) $i->total,
                    'customer'       => $i->customer ? ['company_name' => $i->customer->company_name] : null,
                    'updated_at'     => optional($i->updated_at)->toIso8601String(),
                ]);
        }

        // 却下された請求書（一般メンバー向け）。管理者は承認側なので不要
        $rejectedInvoices = collect();
        if ($user && ! $isAdmin) {
            $rejectedInvoices = Invoice::with('customer')
                ->where('approval_status', 'rejected')
                ->orderBy('updated_at', 'desc')
                ->limit(20)
                ->get()
                ->map(fn($i) => [
                    'id'             => $i->id,
                    'invoice_number' => $i->invoice_number,
                    'doc_type'       => 'invoice',
                    'total'          => (int) $i->total,
                    'customer'       => $i->customer ? ['company_name' => $i->customer->company_name] : null,
                    'approval_comment' => $i->approval_comment,
                    'updated_at'     => optional($i->updated_at)->toIso8601String(),
                ]);
        }

        return response()->json([
            'overdue_tasks'            => $overdueTasks,
            'overdue_tasks_count'      => $overdueTasks->count(),
            'pending_approvals'        => $pendingApprovals,
            'pending_approvals_count'  => $pendingApprovals->count(),
            'rejected_invoices'        => $rejectedInvoices,
            'rejected_invoices_count'  => $rejectedInvoices->count(),
        ]);
    }
}
