<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Engineer;
use App\Models\SesContract;
use App\Services\DealImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;

class SesContractController extends Controller
{
    private function formatDeal(Deal $deal): array
    {
        $sc = $deal->sesContract;
        $eg = $deal->engineer;
        $cu = $deal->customer;
        $wr = $deal->latestWorkRecord;
        $daysUntilExpiry = null;
        if ($sc?->contract_period_end) {
            $daysUntilExpiry = (int) now()->diffInDays($sc->contract_period_end, false);
        }
        return [
            'id'                           => $deal->id,
            'project_number'               => $deal->project_number,
            'engineer_name'                => $eg?->name,
            'change_type'                  => $deal->change_type,
            'affiliation'                  => $deal->affiliation,
            'affiliation_contact'          => $deal->affiliation_contact,
            'sales_person'                 => $deal->sales_person,
            'assignees'                    => $deal->assignees->map(fn($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'email'                        => $eg?->email,
            'phone'                        => $eg?->phone,
            'customer_name'                => $cu?->company_name,
            'end_client'                   => $deal->end_client,
            'project_name'                 => $deal->title,
            'nearest_station'              => $deal->nearest_station,
            'status'                       => $deal->status,
            'invoice_number'               => $deal->invoice_number,
            'client_contact'               => $deal->client_contact,
            'client_mobile'                => $deal->client_mobile,
            'client_phone'                 => $deal->client_phone,
            'client_fax'                   => $deal->client_fax,
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
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $userFilter = $this->resolveUserFilter($request);

        $query = Deal::with(['sesContract', 'engineer', 'customer', 'latestWorkRecord', 'assignees'])
            ->where('deals.tenant_id', $tenantId)
            ->where('deals.deal_type', 'ses')
            ->whereNull('deals.deleted_at');
        if ($userFilter) {
            // deal_assignees 経由でフィルタ（複数担当対応）
            $query->whereHas('assignees', fn($q) => $q->where('users.id', $userFilter));
        }
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('deals.title', 'ilike', "%{$search}%")
                  ->orWhereHas('engineer', fn($q) => $q->where('name', 'ilike', "%{$search}%"))
                  ->orWhereHas('customer', fn($q) => $q->where('company_name', 'ilike', "%{$search}%"));
            });
        }
        if ($status = $request->get('status')) {
            $query->where('deals.status', $status);
        }
        $query->leftJoin('ses_contracts', 'deals.id', '=', 'ses_contracts.deal_id');
        // 顧客名ソート用 JOIN
        if ($request->get('sort_by') === 'customer_name') {
            $query->leftJoin('customers', 'deals.customer_id', '=', 'customers.id');
        }
        // 氏名ソート用 JOIN
        if ($request->get('sort_by') === 'engineer_name') {
            $query->leftJoin('engineers', 'deals.engineer_id', '=', 'engineers.id');
        }
        if ($request->get('sort_by')) {
            [$sortCol, $sortDir] = $this->resolveSort($request, [
                'project_number'      => 'deals.project_number',
                'contract_period_end' => 'ses_contracts.contract_period_end',
                'income_amount'       => 'ses_contracts.income_amount',
                'status'              => 'deals.status',
                'customer_name'       => 'customers.company_name',
                'project_name'        => 'deals.title',
                'engineer_name'       => 'engineers.name',
                'change_type'         => 'deals.change_type',
                'affiliation'         => 'deals.affiliation',
                'end_client'          => 'deals.end_client',
            ], 'ses_contracts.contract_period_end', 'asc');
            $query->orderBy($sortCol, $sortDir);
        } else {
            $query->orderByRaw('CASE WHEN ses_contracts.contract_period_end IS NULL THEN 1 ELSE 0 END')
                  ->orderBy('ses_contracts.contract_period_end', 'asc');
        }
        $query->select('deals.*');
        $paginated = $query->paginate($request->get('per_page', 50));
        $items = $paginated->map(fn(Deal $deal) => $this->formatDeal($deal));
        return response()->json([
            'data' => $items,
            'meta' => ['current_page' => $paginated->currentPage(), 'last_page' => $paginated->lastPage(), 'total' => $paginated->total()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $deal = Deal::with(['sesContract', 'engineer', 'customer', 'latestWorkRecord'])
            ->where('tenant_id', $tenantId)->where('deal_type', 'ses')->findOrFail($id);
        return response()->json(['data' => $this->formatDeal($deal)]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $userId   = auth()->id();
        $v = $request->validate([
            'engineer_name'               => 'required|string|max:100',
            'customer_name'               => 'required|string|max:200',
            'project_name'                => 'nullable|string|max:200',
            'end_client'                  => 'nullable|string|max:200',
            'affiliation'                 => 'nullable|string|max:100',
            'affiliation_contact'         => 'nullable|string|max:100',
            'email'                       => 'nullable|email|max:200',
            'phone'                       => 'nullable|string|max:50',
            'change_type'                 => 'nullable|string|max:50',
            'nearest_station'             => 'nullable|string|max:100',
            'status'                      => 'nullable|string|max:20',
            'invoice_number'              => 'nullable|string',
            'income_amount'               => 'nullable|numeric',
            'billing_plus_22'             => 'nullable|numeric',
            'billing_plus_29'             => 'nullable|numeric',
            'sales_support_payee'         => 'nullable|string|max:200',
            'sales_support_fee'           => 'nullable|numeric',
            'adjustment_amount'           => 'nullable|numeric',
            'profit'                      => 'nullable|numeric',
            'profit_rate_29'              => 'nullable|numeric',
            'client_deduction_unit_price' => 'nullable|numeric',
            'client_deduction_hours'      => 'nullable|numeric',
            'client_overtime_unit_price'  => 'nullable|numeric',
            'client_overtime_hours'       => 'nullable|numeric',
            'settlement_unit_minutes'     => 'nullable|integer',
            'payment_site'                => 'nullable|integer',
            'vendor_deduction_unit_price' => 'nullable|numeric',
            'vendor_deduction_hours'      => 'nullable|numeric',
            'vendor_overtime_unit_price'  => 'nullable|numeric',
            'vendor_overtime_hours'       => 'nullable|numeric',
            'vendor_payment_site'         => 'nullable|integer',
            'contract_start'              => 'nullable|date',
            'contract_period_start'       => 'nullable|date',
            'contract_period_end'         => 'nullable|date',
            'affiliation_period_end'      => 'nullable|string|max:50',
        ]);
        $deal = new Deal();
        DB::transaction(function () use ($v, $tenantId, $userId, &$deal) {
            $customer = Customer::firstOrCreate(
                ['tenant_id' => $tenantId, 'company_name' => $v['customer_name']],
                ['tenant_id' => $tenantId, 'company_name' => $v['customer_name']]
            );
            $engineerKey = !empty($v['email'])
                ? ['tenant_id' => $tenantId, 'email' => $v['email'], 'name' => $v['engineer_name']]
                : ['tenant_id' => $tenantId, 'name' => $v['engineer_name']];
            $engineer = Engineer::updateOrCreate($engineerKey, [
                'tenant_id'           => $tenantId,
                'name'                => $v['engineer_name'],
                'email'               => $v['email'] ?? null,
                'phone'               => $v['phone'] ?? null,
                'affiliation'         => $v['affiliation'] ?? null,
                'affiliation_contact' => $v['affiliation_contact'] ?? null,
                'affiliation_type'    => $this->resolveAffiliationType($v['affiliation'] ?? null),
            ]);
            $deal = Deal::create([
                'tenant_id' => $tenantId, 'user_id' => $userId,
                'customer_id' => $customer->id, 'engineer_id' => $engineer->id, 'contact_id' => null,
                'title'               => $v['project_name'] ?? ($v['engineer_name'] . ' / ' . $v['customer_name']),
                'deal_type'           => 'ses',
                'status'              => $v['status'] ?? '稼働中',
                'amount'              => $v['income_amount'] ?? 0,
                'end_client'          => $v['end_client'] ?? null,
                'affiliation'         => $v['affiliation'] ?? null,
                'affiliation_contact' => $v['affiliation_contact'] ?? null,
                'nearest_station'     => $v['nearest_station'] ?? null,
                'change_type'         => $v['change_type'] ?? null,
                'invoice_number'      => $v['invoice_number'] ?? null,
            ]);
            SesContract::create([
                'tenant_id' => $tenantId, 'deal_id' => $deal->id,
                'income_amount'               => $v['income_amount'] ?? null,
                'billing_plus_22'             => $v['billing_plus_22'] ?? null,
                'billing_plus_29'             => $v['billing_plus_29'] ?? null,
                'sales_support_payee'         => $v['sales_support_payee'] ?? null,
                'sales_support_fee'           => $v['sales_support_fee'] ?? null,
                'adjustment_amount'           => $v['adjustment_amount'] ?? null,
                'profit'                      => $v['profit'] ?? null,
                'profit_rate_29'              => $v['profit_rate_29'] ?? null,
                'client_deduction_unit_price' => $v['client_deduction_unit_price'] ?? null,
                'client_deduction_hours'      => $v['client_deduction_hours'] ?? null,
                'client_overtime_unit_price'  => $v['client_overtime_unit_price'] ?? null,
                'client_overtime_hours'       => $v['client_overtime_hours'] ?? null,
                'settlement_unit_minutes'     => $v['settlement_unit_minutes'] ?? null,
                'payment_site'                => $v['payment_site'] ?? null,
                'vendor_deduction_unit_price' => $v['vendor_deduction_unit_price'] ?? null,
                'vendor_deduction_hours'      => $v['vendor_deduction_hours'] ?? null,
                'vendor_overtime_unit_price'  => $v['vendor_overtime_unit_price'] ?? null,
                'vendor_overtime_hours'       => $v['vendor_overtime_hours'] ?? null,
                'vendor_payment_site'         => $v['vendor_payment_site'] ?? null,
                'contract_start'              => $v['contract_start'] ?? null,
                'contract_period_start'       => $v['contract_period_start'] ?? null,
                'contract_period_end'         => $v['contract_period_end'] ?? null,
                'affiliation_period_end'      => $v['affiliation_period_end'] ?? null,
            ]);
        });
        if ($deal->id) {
            $deal->load(['sesContract', 'engineer', 'customer', 'latestWorkRecord']);
        }
        return response()->json(['data' => $this->formatDeal($deal)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $deal = Deal::where('tenant_id', $tenantId)->where('deal_type', 'ses')->findOrFail($id);
        DB::transaction(function () use ($request, $deal, $tenantId) {

            // ── deals テーブル更新 ────────────────────────────
            $dealFields = array_filter([
                'title'               => $request->input('project_name'),
                'status'              => $request->input('status'),
                'end_client'          => $request->input('end_client'),
                'affiliation'         => $request->input('affiliation'),
                'affiliation_contact' => $request->input('affiliation_contact'),
                'nearest_station'     => $request->input('nearest_station'),
                'change_type'         => $request->input('change_type'),
                'invoice_number'      => $request->input('invoice_number'),
                'amount'              => $request->input('income_amount', 0),
                'sales_person'        => $request->input('sales_person'),
                // 客先担当者
                'client_contact'      => $request->input('client_contact'),
                'client_mobile'       => $request->input('client_mobile'),
                'client_phone'        => $request->input('client_phone'),
                'client_fax'          => $request->input('client_fax'),
                'notes'               => $request->input('notes'),
            ], fn($v) => $v !== null);
            $deal->update($dealFields);

            // ── ses_contracts テーブル更新 ────────────────────
            $scData = array_filter([
                'income_amount'               => $request->input('income_amount'),
                'billing_plus_22'             => $request->input('billing_plus_22'),
                'billing_plus_29'             => $request->input('billing_plus_29'),
                'sales_support_payee'         => $request->input('sales_support_payee'),
                'sales_support_fee'           => $request->input('sales_support_fee'),
                'adjustment_amount'           => $request->input('adjustment_amount'),
                'profit'                      => $request->input('profit'),
                'profit_rate_29'              => $request->input('profit_rate_29'),
                'client_deduction_unit_price' => $request->input('client_deduction_unit_price'),
                'client_deduction_hours'      => $request->input('client_deduction_hours'),
                'client_overtime_unit_price'  => $request->input('client_overtime_unit_price'),
                'client_overtime_hours'       => $request->input('client_overtime_hours'),
                'settlement_unit_minutes'     => $request->input('settlement_unit_minutes'),
                'payment_site'                => $request->input('payment_site'),
                'vendor_deduction_unit_price' => $request->input('vendor_deduction_unit_price'),
                'vendor_deduction_hours'      => $request->input('vendor_deduction_hours'),
                'vendor_overtime_unit_price'  => $request->input('vendor_overtime_unit_price'),
                'vendor_overtime_hours'       => $request->input('vendor_overtime_hours'),
                'vendor_payment_site'         => $request->input('vendor_payment_site'),
                'contract_start'              => $request->input('contract_start'),
                'contract_period_start'       => $request->input('contract_period_start'),
                'contract_period_end'         => $request->input('contract_period_end'),
                'affiliation_period_end'      => $request->input('affiliation_period_end'),
            ], fn($v) => $v !== null);
            SesContract::updateOrCreate(
                ['deal_id' => $deal->id],
                array_merge(['tenant_id' => $tenantId, 'deal_id' => $deal->id], $scData)
            );

            // ── work_records テーブル更新 ─────────────────────
            // 勤務表受領日・交通費・請求書受領日が1つでもあれば保存
            $timesheetDate = $request->input('timesheet_received_date');
            $invoiceDate   = $request->input('invoice_received_date');
            $transportFee  = $request->input('transportation_fee');

            if ($timesheetDate || $invoiceDate || $transportFee !== null) {
                // year_month: 契約期間終了月 → なければ現在月
                $contractEnd = $request->input('contract_period_end');
                $yearMonth = $contractEnd
                    ? \Carbon\Carbon::parse($contractEnd)->format('Y-m')
                    : now()->format('Y-m');

                \App\Models\WorkRecord::updateOrCreate(
                    ['deal_id' => $deal->id, 'year_month' => $yearMonth],
                    [
                        'tenant_id'               => $tenantId,
                        'timesheet_received_date' => $timesheetDate ?: null,
                        'transportation_fee'      => $transportFee,
                        'invoice_received_date'   => $invoiceDate ?: null,
                    ]
                );
            }
        });
        $deal->load(['sesContract', 'engineer', 'customer', 'latestWorkRecord']);
        return response()->json(['data' => $this->formatDeal($deal)]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', File::types(['xlsx', 'xlsm', 'xls'])->max(10 * 1024)],
        ]);
        $uploadedFile = $request->file('file');
        $tmpPath = $uploadedFile->store('imports/tmp', 'local');
        $fullTmpPath = Storage::disk('local')->path($tmpPath);
        try {
            $service = new DealImportService(
                tenantId:   auth()->user()->tenant_id,
                importedBy: auth()->id(),
            );
            $log = $service->import($fullTmpPath, $uploadedFile->getClientOriginalName());
        } finally {
            Storage::disk('local')->delete($tmpPath);
        }
        return response()->json([
            'message' => $log->status === 'failed' ? 'インポートに失敗しました。'
                : "インポートが完了しました（新規: {$log->created_count}件、更新: {$log->updated_count}件）",
            'log' => [
                'id' => $log->id, 'status' => $log->status,
                'total_rows' => $log->total_rows, 'created_count' => $log->created_count,
                'updated_count' => $log->updated_count, 'skipped_count' => $log->skipped_count,
                'error_count' => $log->error_count, 'error_details' => $log->error_details ?? [],
            ],
        ], $log->status === 'failed' ? 422 : 200);
    }


    public function promote(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $deal = Deal::where('tenant_id', $tenantId)
            ->where('deal_type', 'ses')
            ->findOrFail($id);

        $existing = Deal::where('tenant_id', $tenantId)
            ->where('deal_type', 'general')
            ->where('customer_id', $deal->customer_id)
            ->where('title', $deal->title)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'すでに商談管理に登録済みです',
                'deal_id' => $existing->id,
            ]);
        }

        $newDeal = Deal::create([
            'tenant_id'   => $tenantId,
            'user_id'     => auth()->id(),
            'customer_id' => $deal->customer_id,
            'contact_id'  => $deal->contact_id,
            'title'       => $deal->title,
            'deal_type'   => 'general',
            'status'      => '新規',
            'amount'      => $deal->sesContract?->income_amount ?? 0,
            'notes'       => "SES台帳（ID:{$deal->id}）から登録",
        ]);

        return response()->json([
            'message' => '商談管理に登録しました',
            'deal_id' => $newDeal->id,
        ], 201);
    }

    /**
     * 所属（affiliation）から engineer.affiliation_type を推測する
     */
    private function resolveAffiliationType(?string $affiliation): string
    {
        if ($affiliation === null || $affiliation === '') {
            return 'self';
        }
        if (str_contains($affiliation, '個人事業主')) {
            return 'freelance';
        }
        if ($affiliation === '社員') {
            return 'self';
        }
        return 'bp';
    }

    public function summary(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $deals = Deal::with('sesContract')
            ->where('tenant_id', $tenantId)->where('deal_type', 'ses')->whereNull('deleted_at')->get();
        $totalIncome   = $deals->sum(fn($d) => $d->sesContract?->income_amount ?? 0);
        $totalProfit   = $deals->sum(fn($d) => $d->sesContract?->profit ?? 0);
        $activeCount   = $deals->where('status', '稼働中')->count();
        $expiringCount = $deals->filter(fn($d) =>
            $d->sesContract?->contract_period_end &&
            now()->diffInDays($d->sesContract->contract_period_end, false) <= 30 &&
            now()->diffInDays($d->sesContract->contract_period_end, false) >= 0
        )->count();
        return response()->json([
            'total_income' => $totalIncome, 'total_profit' => $totalProfit,
            'active_count' => $activeCount, 'expiring_count' => $expiringCount,
        ]);
    }
}
