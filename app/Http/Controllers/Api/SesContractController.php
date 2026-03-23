<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SesContractController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $query = Deal::with(['sesContract', 'contact', 'customer', 'latestWorkRecord'])
            ->where('deals.tenant_id', $tenantId)
            ->where('deals.deal_type', 'ses')
            ->whereNull('deals.deleted_at');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('deals.title', 'ilike', "%{$search}%")
                  ->orWhereHas('contact', fn($q) => $q->where('name', 'ilike', "%{$search}%"))
                  ->orWhereHas('customer', fn($q) => $q->where('company_name', 'ilike', "%{$search}%"));
            });
        }

        if ($status = $request->get('status')) {
            $query->where('deals.status', $status);
        }

        $query->leftJoin('ses_contracts', 'deals.id', '=', 'ses_contracts.deal_id')
              ->orderByRaw('CASE WHEN ses_contracts.contract_period_end IS NULL THEN 1 ELSE 0 END')
              ->orderBy('ses_contracts.contract_period_end', 'asc')
              ->select('deals.*');

        $paginated = $query->paginate($request->get('per_page', 50));

        $items = $paginated->map(function (Deal $deal) {
            $sc = $deal->sesContract;
            $c  = $deal->contact;
            $cu = $deal->customer;
            $wr = $deal->latestWorkRecord;

            $daysUntilExpiry = null;
            if ($sc?->contract_period_end) {
                $daysUntilExpiry = (int) now()->diffInDays($sc->contract_period_end, false);
            }

            return [
                'id'                           => $deal->id,
                'project_number'               => $deal->project_number,
                'engineer_name'                => $c?->name,
                'change_type'                  => $deal->change_type,
                'affiliation'                  => $deal->affiliation,
                'affiliation_contact'          => $deal->affiliation_contact,
                'email'                        => $c?->email,
                'phone'                        => $c?->phone,
                'customer_name'                => $cu?->company_name,
                'end_client'                   => $deal->end_client,
                'project_name'                 => $deal->title,
                'nearest_station'              => $deal->nearest_station,
                'status'                       => $deal->status,
                'invoice_number'               => $deal->invoice_number,
                'income_amount'                => $sc?->income_amount,
                'billing_plus_22'              => $sc?->billing_plus_22,
                'billing_plus_29'              => $sc?->billing_plus_29,
                'sales_support_payee'          => $sc?->sales_support_payee,
                'sales_support_fee'            => $sc?->sales_support_fee,
                'adjustment_amount'            => $sc?->adjustment_amount,
                'profit'                       => $sc?->profit,
                'profit_rate_29'               => $sc?->profit_rate_29,
                'client_deduction_unit_price'  => $sc?->client_deduction_unit_price,
                'client_deduction_hours'       => $sc?->client_deduction_hours,
                'client_overtime_unit_price'   => $sc?->client_overtime_unit_price,
                'client_overtime_hours'        => $sc?->client_overtime_hours,
                'settlement_unit_minutes'      => $sc?->settlement_unit_minutes,
                'payment_site'                 => $sc?->payment_site,
                'vendor_deduction_unit_price'  => $sc?->vendor_deduction_unit_price,
                'vendor_deduction_hours'       => $sc?->vendor_deduction_hours,
                'vendor_overtime_unit_price'   => $sc?->vendor_overtime_unit_price,
                'vendor_overtime_hours'        => $sc?->vendor_overtime_hours,
                'vendor_payment_site'          => $sc?->vendor_payment_site,
                'contract_start'               => $sc?->contract_start,
                'contract_period_start'        => $sc?->contract_period_start,
                'contract_period_end'          => $sc?->contract_period_end,
                'affiliation_period_end'       => $sc?->affiliation_period_end,
                'timesheet_received_date'      => $wr?->timesheet_received_date,
                'transportation_fee'           => $wr?->transportation_fee,
                'invoice_exists'               => $wr?->invoice_exists,
                'invoice_received_date'        => $wr?->invoice_received_date,
                'notes'                        => $wr?->notes ?? $deal->notes,
                'days_until_expiry'            => $daysUntilExpiry,
            ];
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    public function summary(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $deals = Deal::with('sesContract')
            ->where('tenant_id', $tenantId)
            ->where('deal_type', 'ses')
            ->whereNull('deleted_at')
            ->get();

        $totalIncome   = $deals->sum(fn($d) => $d->sesContract?->income_amount ?? 0);
        $totalProfit   = $deals->sum(fn($d) => $d->sesContract?->profit ?? 0);
        $activeCount   = $deals->where('status', '稼働中')->count();
        $expiringCount = $deals->filter(fn($d) =>
            $d->sesContract?->contract_period_end &&
            now()->diffInDays($d->sesContract->contract_period_end, false) <= 30 &&
            now()->diffInDays($d->sesContract->contract_period_end, false) >= 0
        )->count();

        return response()->json([
            'total_income'   => $totalIncome,
            'total_profit'   => $totalProfit,
            'active_count'   => $activeCount,
            'expiring_count' => $expiringCount,
        ]);
    }
}
