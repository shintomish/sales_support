<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\ClaudeService;
use App\Services\InvoiceCreationService;
use App\Services\InvoiceNumberService;
use App\Services\InvoicePdfService;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 請求書 CRUD（Phase C）
 *
 * 案件×月で1請求書を発行・編集・削除する。
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceCreationService $creationService,
        private readonly InvoicePdfService $pdfService,
        private readonly InvoiceNumberService $numberService,
        private readonly SupabaseStorageService $storage,
        private readonly ClaudeService $claude,
    ) {}

    /**
     * POST /api/v1/estimates/translate-title
     *  英文見積モードで案件選択時に件名を英訳プレビューする。
     */
    public function translateTitle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ja_title' => ['required', 'string', 'max:255'],
        ]);
        $en = $this->claude->translateProjectTitle($validated['ja_title']);
        return response()->json(['en_title' => $en]);
    }

    /** GET /api/v1/invoices */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'doc_type'        => ['nullable', 'in:invoice,estimate,purchase_order'],
            'year_month'      => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'customer_id'     => ['nullable', 'integer'],
            'status'          => ['nullable', 'in:draft,issued'],
            'approval_status' => ['nullable', 'in:draft,pending,approved,rejected'],
            'q'               => ['nullable', 'string', 'max:200'],
        ]);

        $docType = $validated['doc_type'] ?? 'invoice';

        $query = Invoice::with(['customer:id,company_name', 'deal:id,title'])
            ->where('doc_type', $docType)
            ->orderByDesc('issued_date')
            ->orderByDesc('id');

        if (!empty($validated['year_month'])) {
            $query->where('year_month', $validated['year_month']);
        }
        if (!empty($validated['customer_id'])) {
            $query->where('customer_id', $validated['customer_id']);
        }
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (!empty($validated['approval_status'])) {
            $query->where('approval_status', $validated['approval_status']);
        }
        if (!empty($validated['q'])) {
            $like = '%' . $validated['q'] . '%';
            $query->where(function ($q) use ($like) {
                $q->where('invoice_number', 'ilike', $like)
                  ->orWhere('customer_name_snapshot', 'ilike', $like);
            });
        }

        return response()->json($query->paginate(50));
    }

    /** POST /api/v1/invoices  - 案件×月から発行 */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'deal_id'     => ['required', 'integer'],
            'year_month'  => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'issued_date' => ['nullable', 'date'],
            'due_date'    => ['nullable', 'date'],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ]);

        $deal = Deal::query()->findOrFail($validated['deal_id']);

        // 同 deal × year_month の「請求書」が既に存在する場合はエラー（見積書は別扱い）
        $exists = Invoice::where('deal_id', $deal->id)
            ->where('year_month', $validated['year_month'])
            ->where('doc_type', 'invoice')
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'deal_id' => ['この案件・年月の請求書は既に発行済みです'],
            ]);
        }

        $invoice = $this->creationService->createFromDeal($deal, $validated['year_month'], [
            'issued_date' => $validated['issued_date'] ?? null,
            'due_date'    => $validated['due_date'] ?? null,
            'notes'       => $validated['notes'] ?? null,
        ]);

        return response()->json($invoice->load('lines'), 201);
    }

    /**
     * POST /api/v1/estimates  - 見積書を作成（勤務表非依存）
     *
     *   通常モード: deal_id 指定 → SES台帳 (sesContract) から件名/作業期間/単価/控除/超過/精算単位/担当者を自動転記
     *   例外モード: customer_id 指定 → 空テンプレート + 既定明細1行
     *
     * 見積番号は EST-XXX-YYYYMM-NNN 形式で自動採番。
     * 通常モード時は ses_contracts.quote_number に発行番号をフィードバック。
     */
    public function storeEstimate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'deal_id'          => ['nullable', 'integer'],   // 通常モード
            'customer_id'      => ['nullable', 'integer'],   // 例外モード
            'issued_date'      => ['nullable', 'date'],
            'valid_until_text' => ['nullable', 'string', 'max:50'],
            'subject_name'     => ['nullable', 'string', 'max:255'],
            'language'         => ['nullable', 'in:ja,en'],
            'notes'            => ['nullable', 'string', 'max:2000'],
        ]);
        $language = $validated['language'] ?? 'ja';
        $isEnglish = $language === 'en';
        if (empty($validated['deal_id']) && empty($validated['customer_id'])) {
            throw ValidationException::withMessages([
                'customer_id' => ['取引先または案件 (SES台帳) を指定してください'],
            ]);
        }

        $user   = \Illuminate\Support\Facades\Auth::user();
        $tenant = \App\Models\Tenant::find($user?->tenant_id);

        // 通常モード: deal_id → SES契約 / 顧客
        $deal = null;
        $contract = null;
        if (!empty($validated['deal_id'])) {
            $deal = Deal::with(['customer', 'sesContract'])->findOrFail($validated['deal_id']);
            $customer = $deal->customer;
            $contract = $deal->sesContract;
            if (!$customer) {
                throw ValidationException::withMessages(['deal_id' => ['案件に取引先が紐付いていません']]);
            }
        } else {
            $customer = \App\Models\Customer::findOrFail($validated['customer_id']);
        }

        if (empty($customer->invoice_code)) {
            throw ValidationException::withMessages([
                'customer_id' => ['この顧客には顧客コード(invoice_code)が設定されていないため見積書を発行できません'],
            ]);
        }
        $issuedDate    = $validated['issued_date'] ?? now()->toDateString();
        $yearMonth     = \Carbon\Carbon::parse($issuedDate)->format('Y-m');
        $invoiceNumber = $this->numberService->generate($customer, $yearMonth, 'estimate');

        // 件名: 入力値 → 案件タイトル → null
        $subject = $validated['subject_name'] ?? $deal?->title;

        // 作業期間文言と月数を契約から算出（通常モード時）
        //   見積書は「延長」を前提とするため、見積上の作業期間は
        //   現契約終了月の「翌月1日」～ +3ヶ月−1日 をデフォルトとする。
        //   (契約終了日が月末の29/28等の場合でも、起点は常に翌月1日に丸める)
        $workPeriod = null;
        $months = 1;
        if ($contract?->contract_period_end) {
            $from = \Carbon\Carbon::parse($contract->contract_period_end)->addMonth()->startOfMonth();
            $to   = $from->copy()->addMonths(3)->subDay();
            if ($isEnglish) {
                // 英文: "1 Apr 2026 - 30 Jun 2026"
                $workPeriod = sprintf('%d %s %d - %d %s %d',
                    $from->day, $from->format('M'), $from->year,
                    $to->day,   $to->format('M'),   $to->year);
            } else {
                $workPeriod = sprintf('%d年%d月%d日〜%d年%d月%d日',
                    $from->year, $from->month, $from->day,
                    $to->year,   $to->month,   $to->day);
            }
            $months = 3;
        }

        $invoice = Invoice::create([
            'tenant_id'                            => $tenant?->id,
            'doc_type'                             => 'estimate',
            'language'                             => $language,
            'deal_id'                              => $deal?->id,
            'customer_id'                          => $customer->id,
            'year_month'                           => $yearMonth,
            'invoice_number'                       => $invoiceNumber,
            'issued_date'                          => $issuedDate,
            'valid_until_text'                     => $validated['valid_until_text'] ?? '30日間',
            'subject_name'                         => $subject,
            'work_period_text'                     => $workPeriod,
            'notes'                                => $validated['notes'] ?? null,
            'status'                               => 'draft',
            'approval_status'                      => 'draft',
            'subtotal'                             => 0,
            'tax'                                  => 0,
            'total'                                => 0,
            'delivery_date_text'                   => '御社ご指定日',
            'delivery_place_text'                  => '御社ご指定場所',
            'payment_terms_text'                   => '月末締め翌々月20日現金お支払',
            'transportation_note_text'             => 'お客様指示の下、移動が発生した場合は別途実費にて御請求致します。',
            // SES契約からの snapshot（通常モード時のみ非 null）
            'engineer_name_snapshot'               => $contract?->engineer_name,
            'settlement_unit_minutes_snapshot'     => $contract?->settlement_unit_minutes,
            'client_deduction_hours_snapshot'      => $contract?->client_deduction_hours,
            'client_overtime_hours_snapshot'       => $contract?->client_overtime_hours,
            'client_deduction_unit_price_snapshot' => $contract?->client_deduction_unit_price,
            'client_overtime_unit_price_snapshot'  => $contract?->client_overtime_unit_price,
            'customer_name_snapshot'               => $isEnglish
                ? ($customer->company_name_en ?: $customer->company_name)
                : $customer->company_name,
            'customer_address_snapshot'            => $customer->address,
            'issuer_name_snapshot'                 => $tenant?->invoice_issuer_name,
            'issuer_postal_code_snapshot'          => $tenant?->invoice_issuer_postal_code,
            'issuer_address_snapshot'              => $tenant?->invoice_issuer_address,
            'issuer_tel_snapshot'                  => $tenant?->invoice_issuer_tel,
            'issuer_fax_snapshot'                  => $tenant?->invoice_issuer_fax,
            'issuer_logo_snapshot'                 => $tenant?->invoice_issuer_logo_path,
            'issuer_round_seal_snapshot'           => $tenant?->invoice_issuer_round_seal_path,
            'issuer_square_seal_snapshot'          => $tenant?->invoice_issuer_square_seal_path,
            'issuer_url_snapshot'                  => $tenant?->invoice_issuer_url,
            'issuer_invoice_number_snapshot'       => $tenant?->invoice_issuer_invoice_number,
        ]);

        // 既定の1行: 通常モードは基本月額の単価×月数、例外モードは空行
        $basicUnitPrice = (float) ($contract?->income_amount ?? 0);
        $qty = $contract ? $months : 1;
        if ($basicUnitPrice > 0) {
            // 「基本月額」ラベルは PDF 側で付与されるため description には金額のみ保持
            $description = $isEnglish
                ? sprintf('%s yen/month', number_format($basicUnitPrice))
                : sprintf('%s円', number_format($basicUnitPrice));
        } else {
            $description = '';
        }
        InvoiceLine::create([
            'invoice_id'  => $invoice->id,
            'sort_order'  => 0,
            'description' => $description,
            'quantity'    => $qty,
            'unit'        => null,
            'unit_price'  => $basicUnitPrice,
            'tax_rate'    => 0.10,
            'amount'      => round($basicUnitPrice * $qty, 2),
            'is_expense'  => false,
        ]);

        // 通常モード時は SES契約に見積番号をフィードバック
        if ($contract) {
            $contract->quote_number = $invoiceNumber;
            $contract->save();
        }

        $invoice->load('lines');
        $invoice->recalcAmounts();
        $invoice->save();

        return response()->json($invoice->fresh()->load('lines'), 201);
    }

    /**
     * POST /api/v1/purchase-orders  - 注文書を作成（勤務表非依存）
     *
     *   通常モード: deal_id 指定 → SES台帳 (sesContract) から
     *     ・宛先 = deal.affiliation で逆引きした仕入先 customer
     *     ・件名 / 作業期間 / 仕入側精算条件 / 担当者を自動転記
     *   例外モード: customer_id 指定 → 空テンプレ + 既定明細1行
     *
     * 注文番号は ORD-XXX-YYYYMM-NNN、注文請書番号は OCF-XXX-YYYYMM-NNN を同時採番する。
     */
    public function storePurchaseOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'deal_id'      => ['nullable', 'integer'],   // 通常モード
            'customer_id'  => ['nullable', 'integer'],   // 例外モード
            'issued_date'  => ['nullable', 'date'],
            'subject_name' => ['nullable', 'string', 'max:255'],
            'notes'        => ['nullable', 'string', 'max:2000'],
        ]);
        if (empty($validated['deal_id']) && empty($validated['customer_id'])) {
            throw ValidationException::withMessages([
                'customer_id' => ['取引先または案件 (SES台帳) を指定してください'],
            ]);
        }

        $user   = \Illuminate\Support\Facades\Auth::user();
        $tenant = \App\Models\Tenant::find($user?->tenant_id);

        // 通常モード: deal_id → SES契約 / 所属会社(仕入先) を解決
        $deal = null;
        $contract = null;
        if (!empty($validated['deal_id'])) {
            $deal = Deal::with('sesContract')->findOrFail($validated['deal_id']);
            $contract = $deal->sesContract;
            if (empty($deal->affiliation)) {
                throw ValidationException::withMessages([
                    'deal_id' => ['この案件には所属会社が設定されていません。SES台帳を先に編集してください'],
                ]);
            }
            $customer = \App\Models\Customer::withoutGlobalScopes()
                ->where('tenant_id', $deal->tenant_id)
                ->where('is_supplier', true)
                ->where('company_name', $deal->affiliation)
                ->first();
            if (!$customer) {
                throw ValidationException::withMessages([
                    'deal_id' => ["所属会社「{$deal->affiliation}」が顧客マスタの仕入先として登録されていません。先に顧客管理から登録してください"],
                ]);
            }
        } else {
            $customer = \App\Models\Customer::findOrFail($validated['customer_id']);
        }

        if (empty($customer->invoice_code)) {
            throw ValidationException::withMessages([
                'customer_id' => ['この顧客には顧客コード(invoice_code)が設定されていないため注文書を発行できません'],
            ]);
        }
        $issuedDate        = $validated['issued_date'] ?? now()->toDateString();
        $yearMonth         = \Carbon\Carbon::parse($issuedDate)->format('Y-m');
        $invoiceNumber     = $this->numberService->generate($customer, $yearMonth, 'purchase_order');
        $acknowledgementNo = $this->numberService->generate($customer, $yearMonth, 'acknowledgement');

        // 件名: 入力値 → 案件タイトル
        $subject = $validated['subject_name'] ?? $deal?->title;

        // 作業期間: 注文書も「延長」前提 — 現契約終了月の「翌月1日」～ +3ヶ月−1日
        //   (契約終了日が月末でない場合も常に翌月1日始まりに丸める)
        $workPeriod = null;
        $months = 1;
        if ($contract?->contract_period_end) {
            $from = \Carbon\Carbon::parse($contract->contract_period_end)->addMonth()->startOfMonth();
            $to   = $from->copy()->addMonths(3)->subDay();
            $workPeriod = sprintf('%d年%d月%d日〜%d年%d月%d日',
                $from->year, $from->month, $from->day,
                $to->year,   $to->month,   $to->day);
            $months = 3;
        }

        $invoice = Invoice::create([
            'tenant_id'                            => $tenant?->id,
            'doc_type'                             => 'purchase_order',
            'deal_id'                              => $deal?->id,
            'customer_id'                          => $customer->id,
            'year_month'                           => $yearMonth,
            'invoice_number'                       => $invoiceNumber,
            'acknowledgement_no'                   => $acknowledgementNo,
            'issued_date'                          => $issuedDate,
            'subject_name'                         => $subject,
            'work_period_text'                     => $workPeriod,
            'notes'                                => $validated['notes'] ?? null,
            'status'                               => 'draft',
            'approval_status'                      => 'draft',
            'subtotal'                             => 0,
            'tax'                                  => 0,
            'total'                                => 0,
            'delivery_date_text'                   => '弊社指定日',
            'delivery_place_text'                  => '弊社指定場所',
            'payment_terms_text'                   => '月末締め翌々月25日現金お支払',
            'transportation_note_text'             => '弊社指示の下、移動が発生した場合は別途実費にてご請求下さい。',
            // SES契約からの snapshot（通常モード時のみ） - 仕入側精算
            'engineer_name_snapshot'               => $contract?->engineer_name,
            'settlement_unit_minutes_snapshot'     => $contract?->vendor_settlement_unit_minutes,
            'client_deduction_hours_snapshot'      => $contract?->vendor_deduction_hours,
            'client_overtime_hours_snapshot'       => $contract?->vendor_overtime_hours,
            'client_deduction_unit_price_snapshot' => $contract?->vendor_deduction_unit_price,
            'client_overtime_unit_price_snapshot'  => $contract?->vendor_overtime_unit_price,
            'customer_name_snapshot'               => $customer->company_name,
            'customer_address_snapshot'            => $customer->address,
            'issuer_name_snapshot'                 => $tenant?->invoice_issuer_name,
            'issuer_postal_code_snapshot'          => $tenant?->invoice_issuer_postal_code,
            'issuer_address_snapshot'              => $tenant?->invoice_issuer_address,
            'issuer_tel_snapshot'                  => $tenant?->invoice_issuer_tel,
            'issuer_fax_snapshot'                  => $tenant?->invoice_issuer_fax,
            'issuer_logo_snapshot'                 => $tenant?->invoice_issuer_logo_path,
            'issuer_round_seal_snapshot'           => $tenant?->invoice_issuer_round_seal_path,
            'issuer_square_seal_snapshot'          => $tenant?->invoice_issuer_square_seal_path,
            'issuer_url_snapshot'                  => $tenant?->invoice_issuer_url,
            'issuer_invoice_number_snapshot'       => $tenant?->invoice_issuer_invoice_number,
        ]);

        // 既定の1行: 通常モードは仕入単価(income_amount × 利益除外 ≒ vendor 単価) × 月数
        //   ses_contracts に「仕入単価」専用カラムは無いため、暫定で income_amount を流用する。
        //   実運用で仕入額が別管理になったら別カラムから取得するように直す（要管理部確認）。
        $basicUnitPrice = (float) ($contract?->income_amount ?? 0);
        $qty = $contract ? $months : 1;
        InvoiceLine::create([
            'invoice_id'  => $invoice->id,
            'sort_order'  => 0,
            'description' => $basicUnitPrice > 0
                ? sprintf('%s円', number_format($basicUnitPrice))
                : '',
            'quantity'    => $qty,
            'unit'        => null,
            'unit_price'  => $basicUnitPrice,
            'tax_rate'    => 0.10,
            'amount'      => round($basicUnitPrice * $qty, 2),
            'is_expense'  => false,
        ]);

        $invoice->load('lines');
        $invoice->recalcAmounts();
        $invoice->save();

        return response()->json($invoice->fresh()->load('lines'), 201);
    }

    /** GET /api/v1/invoices/{invoice} */
    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load([
            'lines',
            'customer:id,company_name,invoice_delivery_method,primary_contact_id',
            'customer.contacts:id,customer_id,name,email,position',
            'deal:id,title',
        ]);
        return response()->json($invoice);
    }

    /** PUT /api/v1/invoices/{invoice}  - メタ情報 + 明細を一括更新 */
    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'issued_date'                 => ['nullable', 'date'],
            'due_date'                    => ['nullable', 'date'],
            'valid_until_text'            => ['nullable', 'string', 'max:50'],
            'notes'                       => ['nullable', 'string', 'max:2000'],
            'status'                      => ['nullable', 'in:draft,issued'],
            'order_number'                => ['nullable', 'string', 'max:100'],
            'quote_number'                => ['nullable', 'string', 'max:100'],
            'subject_name'                => ['nullable', 'string', 'max:255'],
            'work_period_text'            => ['nullable', 'string', 'max:100'],
            'work_location'               => ['nullable', 'string', 'max:255'],
            'engineer_name_snapshot'      => ['nullable', 'string', 'max:255'],
            'delivery_items_text'         => ['nullable', 'string', 'max:255'],
            'transportation_note_text'    => ['nullable', 'string', 'max:1000'],
            'delivery_date_text'          => ['nullable', 'string', 'max:100'],
            'delivery_place_text'         => ['nullable', 'string', 'max:100'],
            'payment_terms_text'          => ['nullable', 'string', 'max:100'],
            'invoice_number'              => ['nullable', 'string', 'max:100'],
            'lines'                       => ['nullable', 'array'],
            'lines.*.description'         => ['nullable', 'string', 'max:500'],
            'lines.*.quantity'            => ['required_with:lines', 'numeric'],
            'lines.*.unit'                => ['nullable', 'string', 'max:20'],
            'lines.*.unit_price'          => ['required_with:lines', 'numeric'],
            'lines.*.tax_rate'            => ['required_with:lines', 'numeric', 'in:0,0.08,0.10'],
            'lines.*.is_expense'          => ['nullable', 'boolean'],
        ]);

        $metaKeys = [
            'issued_date', 'due_date', 'valid_until_text', 'notes', 'status',
            'order_number', 'quote_number', 'invoice_number',
            'subject_name', 'work_period_text',
            'work_location', 'delivery_items_text', 'transportation_note_text',
            'engineer_name_snapshot',
            'delivery_date_text', 'delivery_place_text', 'payment_terms_text',
        ];
        $invoice->fill(array_intersect_key($validated, array_flip($metaKeys)));

        if (array_key_exists('lines', $validated)) {
            $invoice->lines()->delete();
            foreach (array_values($validated['lines']) as $i => $line) {
                InvoiceLine::query()->create([
                    'invoice_id'  => $invoice->id,
                    'sort_order'  => $i,
                    'description' => $line['description'] ?? '',
                    'quantity'    => $line['quantity'],
                    'unit'        => $line['unit'] ?? null,
                    'unit_price'  => $line['unit_price'],
                    'tax_rate'    => $line['tax_rate'],
                    'amount'      => round((float) $line['quantity'] * (float) $line['unit_price'], 2),
                    'is_expense'  => (bool) ($line['is_expense'] ?? false),
                ]);
            }
            $invoice->load('lines');
            $invoice->recalcAmounts();
        }

        $invoice->save();
        return response()->json($invoice->load('lines'));
    }

    /**
     * POST /api/v1/invoices/{invoice}/pdf  - PDF を生成・保存
     * draft でも issued でも生成可。生成と同時に status を issued に遷移。
     * 注文書(doc_type=purchase_order)の場合は注文請書 PDF も同時に生成する。
     */
    public function generatePdf(Invoice $invoice): JsonResponse
    {
        $url = $this->pdfService->generateAndStore($invoice);
        if ($invoice->status === 'draft') {
            $invoice->status = 'issued';
            $invoice->save();
        }

        $payload = ['pdf_url' => $url];
        if ($invoice->doc_type === 'purchase_order' && !empty($invoice->acknowledgement_no)) {
            $payload['acknowledgement_pdf_url'] = $this->pdfService->generateAcknowledgementAndStore($invoice);
        }
        $payload['invoice'] = $invoice->fresh()->load('lines');

        return response()->json($payload);
    }

    /**
     * GET /api/v1/invoices/{invoice}/latest-post
     * 該当 invoice の最新郵送記録 + 顧客連絡先候補（モーダル prefill 用）
     */
    public function latestPost(Invoice $invoice): JsonResponse
    {
        $invoice->load('customer:id,company_name', 'customer.contacts:id,customer_id,name,email');

        $candidates = collect($invoice->customer?->contacts ?? [])
            ->map(fn($c) => ['name' => $c->name, 'email' => $c->email])
            ->values();

        $r = \App\Models\InvoiceSendHistory::where('invoice_id', $invoice->id)
            ->where('method', 'post')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'latest' => $r ? [
                'id'               => $r->id,
                'sent_at'          => $r->sent_at?->format('Y-m-d'),
                'note'             => $r->subject,
                'attachments_meta' => $r->attachments_meta ?? [],
                'to_recipients'    => $r->to_emails ?? [],
            ] : null,
            'candidates' => $candidates,
        ]);
    }

    /**
     * POST /api/v1/invoices/{invoice}/record-post
     * 郵送した実績を invoice_send_histories に method=post で記録
     */
    public function recordPost(Request $request, Invoice $invoice): JsonResponse
    {
        $v = $request->validate([
            'sent_at'        => ['nullable', 'date'],
            'note'           => ['nullable', 'string', 'max:1000'],
            'items'          => ['nullable', 'array'],
            'items.*'        => ['string', 'max:50'],
            'to_recipients'  => ['nullable', 'array'],
            'to_recipients.*'=> ['string', 'max:200'],
        ]);

        $user = \Illuminate\Support\Facades\Auth::user();
        $items      = $v['items'] ?? [];
        $recipients = $v['to_recipients'] ?? [];

        $history = \App\Models\InvoiceSendHistory::create([
            'tenant_id'        => $user?->tenant_id,
            'invoice_id'       => $invoice->id,
            'method'           => 'post',
            'to_emails'        => !empty($recipients) ? $recipients : null,
            'cc_emails'        => null,
            'subject'          => $v['note'] ?? null,
            'body'             => null,
            'attachments_meta' => $items,
            'status'           => 'sent',
            'sent_at'          => $v['sent_at'] ?? now()->toDateTimeString(),
            'sent_by'          => $user?->id,
        ]);

        return response()->json([
            'message'    => '郵送記録を保存しました',
            'history_id' => $history->id,
        ], 201);
    }

    /**
     * GET /api/v1/invoices/{invoice}/mail-template
     * メール送信モーダル用のテンプレート（subject/body）を返す。
     * テナントに保存されたテンプレがあればそれを使い、無ければデフォルトを使用。
     * プレースホルダ {invoice_number} {customer_name} {year_month} {total} {due_date} を置換する。
     */
    public function mailTemplate(Invoice $invoice): JsonResponse
    {
        $tenant = \App\Models\Tenant::find($invoice->tenant_id);
        $invoice->load('customer:id,company_name,primary_contact_id,invoice_delivery_method', 'customer.contacts:id,customer_id,name,email');

        $user = \Illuminate\Support\Facades\Auth::user();
        $tpl  = $user ? \App\Models\EmailBodyTemplate::where('tenant_id', $invoice->tenant_id)
            ->where('user_id', $user->id)->first() : null;

        $issuerName = $tenant?->invoice_issuer_name ?? '';
        $userName   = $tpl?->name ?? $user?->name ?? '';
        $userNameEn = $tpl?->name_en;

        [$defaultSubject, $defaultBody, $subjectCol, $bodyCol] = match ($invoice->doc_type) {
            'estimate' => [
                '【お見積書】{invoice_number}',
                "{customer_name} 様\n\n"
                    . "いつも大変お世話になっております。\n"
                    . "{issuer_name}の{user_name}です。\n\n"
                    . "お見積書を添付にてお送りいたします。\n\n"
                    . "見積番号: {invoice_number}\n"
                    . "見積金額: ￥{total}（税込）\n\n"
                    . "ご確認のほど、何卒よろしくお願い申し上げます。\n",
                'estimate_email_subject_template',
                'estimate_email_body_template',
            ],
            'purchase_order' => [
                '【ご注文書】{invoice_number}',
                "{customer_name} 様\n\n"
                    . "いつも大変お世話になっております。\n"
                    . "{issuer_name}の{user_name}です。\n\n"
                    . "注文書を添付にてお送りいたします。\n\n"
                    . "注文番号: {invoice_number}\n"
                    . "注文金額: ￥{total}（税込）\n\n"
                    . "ご確認のほど、何卒よろしくお願い申し上げます。\n",
                'purchase_order_email_subject_template',
                'purchase_order_email_body_template',
            ],
            default => [
                '【請求書】{invoice_number}({year_month_text}分)',
                "{customer_name} 様\n\n"
                    . "いつも大変お世話になっております。\n"
                    . "{issuer_name}の{user_name}です。\n\n"
                    . "{year_month_text}分の請求書を添付にてお送りいたします。\n\n"
                    . "請求番号: {invoice_number}\n"
                    . "請求金額: ￥{total}（税込）\n"
                    . "お支払期限: {due_date}\n\n"
                    . "ご確認のほど、何卒よろしくお願い申し上げます。\n",
                'invoice_email_subject_template',
                'invoice_email_body_template',
            ],
        };

        $subject = $tenant?->{$subjectCol} ?: $defaultSubject;
        $body    = $tenant?->{$bodyCol}    ?: $defaultBody;

        // 署名（社内既定書式）
        $sep = '_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/';
        $sigLines = [$sep];
        if ($issuerName) $sigLines[] = $issuerName;
        $namePart = $userName . ($userNameEn ? '（' . $userNameEn . '）' : '');
        if ($namePart !== '') $sigLines[] = '　' . $namePart;
        $sigLines[] = '';
        if ($tenant?->invoice_issuer_postal_code) $sigLines[] = '　〒' . $tenant->invoice_issuer_postal_code;
        if ($tenant?->invoice_issuer_address)     $sigLines[] = '　' . $tenant->invoice_issuer_address;
        $telLine = '';
        if ($tenant?->invoice_issuer_tel) $telLine .= 'Tel：' . $tenant->invoice_issuer_tel;
        if ($tenant?->invoice_issuer_fax) {
            if ($telLine !== '') $telLine .= '　';
            $telLine .= 'Fax：' . $tenant->invoice_issuer_fax;
        }
        if ($telLine !== '') $sigLines[] = '　' . $telLine;
        $sigLines[] = '';
        $emailAddr = $tpl?->email ?: $user?->email;
        if ($emailAddr) $sigLines[] = '　E-Mail：' . $emailAddr;
        if ($tpl?->mobile) $sigLines[] = '　Mobile：' . $tpl->mobile;
        $sigLines[] = '';
        if ($tenant?->invoice_issuer_url) $sigLines[] = '　URL:' . $tenant->invoice_issuer_url;
        $sigLines[] = $sep;
        $signature = "\n" . implode("\n", $sigLines);

        $body = $body . $signature . "\n";

        // 年月表示: 2026-04 → 2026年04月
        $ymText = $invoice->year_month;
        if (preg_match('/^(\d{4})-(\d{2})$/', $ymText, $m)) {
            $ymText = sprintf('%s年%s月', $m[1], $m[2]);
        }

        $vars = [
            '{invoice_number}'   => $invoice->invoice_number,
            '{customer_name}'    => $invoice->customer_name_snapshot ?? $invoice->customer?->company_name ?? '',
            '{year_month}'       => $invoice->year_month,
            '{year_month_text}'  => $ymText,
            '{total}'            => number_format((float) $invoice->total),
            '{due_date}'         => $invoice->due_date?->format('Y年n月j日') ?? '',
            '{issuer_name}'      => $issuerName,
            '{user_name}'        => $userName,
        ];
        $subject = strtr($subject, $vars);
        $body    = strtr($body, $vars);

        // 送信先候補: customer.contacts のメールアドレス一覧
        $candidates = collect($invoice->customer?->contacts ?? [])
            ->filter(fn($c) => !empty($c->email))
            ->map(fn($c) => ['name' => $c->name, 'email' => $c->email])
            ->values();

        return response()->json([
            'subject'    => $subject,
            'body'       => $body,
            'candidates' => $candidates,
            'delivery_method' => $invoice->customer?->invoice_delivery_method,
        ]);
    }

    /**
     * GET /api/v1/invoices/{invoice}/send-histories
     * 請求書の送信履歴
     */
    public function sendHistories(Invoice $invoice): JsonResponse
    {
        $rows = \App\Models\InvoiceSendHistory::where('invoice_id', $invoice->id)
            ->with('sender:id,name')
            ->orderByDesc('sent_at')
            ->get()
            ->map(fn($r) => [
                'id'               => $r->id,
                'method'           => $r->method,
                'to_emails'        => $r->to_emails,
                'cc_emails'        => $r->cc_emails,
                'subject'          => $r->subject,
                'attachments_meta' => $r->attachments_meta,
                'status'           => $r->status,
                'error_message'    => $r->error_message,
                'sent_at'          => $r->sent_at?->toIso8601String(),
                'sent_by_name'     => $r->sender?->name,
            ]);
        return response()->json(['data' => $rows]);
    }

    /**
     * GET /api/v1/invoice-send-histories
     * テナント横断の送信履歴一覧（フィルタ・ページング付き）
     */
    public function allSendHistories(Request $request): JsonResponse
    {
        $v = $request->validate([
            'year_month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'status'     => ['nullable', 'in:sent,failed'],
            'method'     => ['nullable', 'in:mail,post'],
            'doc_type'   => ['nullable', 'in:invoice,estimate,purchase_order'],
            'q'          => ['nullable', 'string', 'max:200'],
        ]);

        $docType = $v['doc_type'] ?? 'invoice';

        $q = \App\Models\InvoiceSendHistory::query()
            ->with([
                'sender:id,name',
                'invoice:id,doc_type,invoice_number,customer_id,customer_name_snapshot,year_month,total,subject_name',
            ])
            ->whereHas('invoice', fn($iq) => $iq->where('doc_type', $docType))
            ->orderByDesc('sent_at');

        if (!empty($v['status'])) $q->where('status', $v['status']);
        if (!empty($v['method'])) $q->where('method', $v['method']);
        if (!empty($v['year_month'])) {
            $q->whereHas('invoice', fn($iq) => $iq->where('year_month', $v['year_month']));
        }
        if (!empty($v['q'])) {
            $like = '%' . $v['q'] . '%';
            $q->where(function ($qq) use ($like) {
                $qq->where('subject', 'ilike', $like)
                   ->orWhereHas('invoice', fn($iq) =>
                       $iq->where('invoice_number', 'ilike', $like)
                          ->orWhere('customer_name_snapshot', 'ilike', $like)
                          ->orWhere('subject_name', 'ilike', $like));
            });
        }

        $paginated = $q->paginate(50);

        // TO のメールアドレスから連絡先名を解決するためのマップを作る
        $allEmails = collect();
        foreach ($paginated->items() as $r) {
            $allEmails = $allEmails->concat($r->to_emails ?? []);
        }
        $allEmails = $allEmails->unique()->values();
        $emailToName = [];
        if ($allEmails->isNotEmpty()) {
            $emailToName = \App\Models\Contact::query()
                ->whereIn('email', $allEmails)
                ->pluck('name', 'email')
                ->all();
        }

        $paginated->getCollection()->transform(function ($r) use ($emailToName) {
            $toNames = collect($r->to_emails ?? [])
                ->map(fn($em) => $emailToName[$em] ?? $em)
                ->values()->all();
            return [
                'id'                   => $r->id,
                'method'               => $r->method,
                'to_emails'            => $r->to_emails,
                'to_names'             => $toNames,
                'cc_emails'            => $r->cc_emails,
                'subject'              => $r->subject,
                'attachments_meta'     => $r->attachments_meta,
                'status'               => $r->status,
                'error_message'        => $r->error_message,
                'sent_at'              => $r->sent_at?->toIso8601String(),
                'created_at'           => $r->created_at?->toIso8601String(),
                'sent_by_name'         => $r->sender?->name,
                'invoice_id'           => $r->invoice_id,
                'invoice_number'       => $r->invoice?->invoice_number,
                'invoice_year_month'   => $r->invoice?->year_month,
                'invoice_total'        => $r->invoice?->total,
                'invoice_subject_name' => $r->invoice?->subject_name,
                'customer_name'        => $r->invoice?->customer_name_snapshot,
            ];
        });

        return response()->json($paginated);
    }

    /**
     * POST /api/v1/invoices/{invoice}/send-mail
     * 請求書をメールで送付。送付状/勤務表/交通費明細書/封筒を選択添付。
     *
     * 送信先候補は customer.contacts のメールアドレス。
     * 送信履歴は invoice_send_histories に記録。
     */
    public function sendMail(Request $request, Invoice $invoice): JsonResponse
    {
        $v = $request->validate([
            'to_emails'              => ['required', 'array', 'min:1'],
            'to_emails.*'            => ['email'],
            'cc_emails'              => ['nullable', 'array'],
            'cc_emails.*'            => ['email'],
            'subject'                => ['required', 'string', 'max:500'],
            'body'                   => ['required', 'string'],
            'attach_invoice'         => ['nullable', 'boolean'],
            'attachments'            => ['nullable', 'array'],
            'attachments.*'          => ['file', 'max:10240'],
        ]);

        $attachments = [];
        $metaNames   = [];

        // 請求書 PDF を Storage から取得して添付
        if (($v['attach_invoice'] ?? true) && $invoice->pdf_path) {
            try {
                $bin = @file_get_contents($invoice->pdf_path);
                if ($bin !== false) {
                    $name = $invoice->invoice_number . '.pdf';
                    $attachments[] = ['name' => $name, 'content' => $bin, 'mime' => 'application/pdf'];
                    $metaNames[] = $name;
                }
            } catch (\Throwable $e) { report($e); }
        }

        // 任意添付ファイル（ユーザーがアップロードしたもの）
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $name = $file->getClientOriginalName();
                $attachments[] = [
                    'name'    => $name,
                    'content' => file_get_contents($file->getRealPath()),
                    'mime'    => $file->getMimeType() ?: 'application/octet-stream',
                ];
                $metaNames[] = $name;
            }
        }

        $userId  = \Illuminate\Support\Facades\Auth::id();
        $tenantId = \Illuminate\Support\Facades\Auth::user()?->tenant_id;

        $history = \App\Models\InvoiceSendHistory::create([
            'tenant_id'        => $tenantId,
            'invoice_id'       => $invoice->id,
            'method'           => 'mail',
            'to_emails'        => $v['to_emails'],
            'cc_emails'        => $v['cc_emails'] ?? [],
            'subject'          => $v['subject'],
            'body'             => $v['body'],
            'attachments_meta' => $metaNames,
            'status'           => 'sent',
            'sent_at'          => now(),
            'sent_by'          => $userId,
        ]);

        try {
            $mailable = new \App\Mail\InvoiceMail($v['subject'], $v['body'], $attachments);
            $mail = \Illuminate\Support\Facades\Mail::to($v['to_emails']);
            if (!empty($v['cc_emails'])) {
                $mail->cc($v['cc_emails']);
            }
            $mail->send($mailable);
        } catch (\Throwable $e) {
            $history->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            return response()->json(['message' => 'メール送信に失敗しました: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message' => '送信しました',
            'history_id' => $history->id,
        ], 201);
    }

    /**
     * POST /api/v1/invoices/{invoice}/cover-letter
     *   body: { items: [{ name: '御見積書', count: 1, unit: '通' }, ...] }
     * 送付状 PDF をインラインで返す。
     */
    public function coverLetter(Request $request, Invoice $invoice)
    {
        $v = $request->validate([
            'items'           => ['required', 'array', 'min:1'],
            'items.*.name'    => ['required', 'string', 'max:100'],
            'items.*.count'   => ['nullable', 'integer', 'min:1', 'max:9999'],
            'items.*.unit'    => ['nullable', 'string', 'max:20'],
        ]);

        $items = collect($v['items'])
            ->filter(fn($i) => is_array($i) && trim((string)($i['name'] ?? '')) !== '')
            ->map(fn($i) => [
                'name'  => mb_substr(trim($i['name']), 0, 100),
                'count' => (int) ($i['count'] ?? 1) ?: 1,
                'unit'  => mb_substr(trim((string)($i['unit'] ?? '通')) ?: '通', 0, 20),
            ])
            ->values()
            ->all();

        if (empty($items)) {
            return response()->json(['message' => '同封物が選択されていません'], 422);
        }

        $pdf = $this->pdfService->renderCoverLetter($invoice, $items);
        return response($pdf, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="cover-letter-' . $invoice->invoice_number . '.pdf"');
    }

    /**
     * GET /api/v1/invoices/{invoice}/envelope?zaichu_labels[]=注文書在中&zaichu_labels[]=注文書・請書在中
     * 長3封筒 PDF をインラインで返す
     *  - zaichu_labels[] が指定された場合: 各文言の朱印を左下方に縦に並べて印字
     *  - 未指定 / 空配列: 朱印なし（一般用途）
     */
    public function envelope(Request $request, Invoice $invoice)
    {
        $raw = $request->input('zaichu_labels', []);
        $labels = is_array($raw)
            ? collect($raw)
                ->filter(fn($l) => is_string($l) && trim($l) !== '')
                ->map(fn($l) => mb_substr(trim($l), 0, 30))
                ->values()
                ->all()
            : [];
        $pdf = $this->pdfService->renderEnvelope($invoice, $labels);
        return response($pdf, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="envelope-' . $invoice->invoice_number . '.pdf"');
    }

    /**
     * POST /api/v1/invoices/{invoice}/submit-approval
     * 承認申請 → approval_status を 'pending' に
     * 申請者は誰でも可。下書き/却下後の再申請に対応。
     */
    public function submitForApproval(Invoice $invoice): JsonResponse
    {
        if ($invoice->approval_status === 'approved') {
            return response()->json(['message' => '既に承認済みです'], 422);
        }
        $invoice->approval_status  = 'pending';
        $invoice->approval_comment = null;
        // 申請者を記録（承認完了後にこのユーザーへバッジ通知するため）
        $invoice->submitted_by = \Illuminate\Support\Facades\Auth::id();
        $invoice->save();

        return response()->json(['invoice' => $invoice->fresh()->load('lines')]);
    }

    /**
     * POST /api/v1/invoices/{invoice}/approve
     * 承認 → approval_status='approved' + approved=true
     * 電子印は廃止 (2026-05-14)。承認後は紙印刷→物理印→スキャンPDFを差し替える運用。
     * tenant_admin / super_admin のみ実行可
     */
    public function approve(Invoice $invoice): JsonResponse
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if (!$user || !in_array($user->role ?? null, ['tenant_admin', 'super_admin'], true)) {
            return response()->json(['message' => '承認権限がありません'], 403);
        }

        $invoice->approval_status  = 'approved';
        $invoice->approval_comment = null;
        $invoice->approved         = true;
        $invoice->approved_at      = now();
        $invoice->approved_by      = $user->id;
        $invoice->save();

        return response()->json([
            'invoice' => $invoice->fresh()->load('lines'),
        ]);
    }

    /**
     * POST /api/v1/invoices/{invoice}/reject
     * 却下 → approval_status='rejected' + 理由を記録
     * tenant_admin / super_admin のみ実行可
     */
    public function reject(Request $request, Invoice $invoice): JsonResponse
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if (!$user || !in_array($user->role ?? null, ['tenant_admin', 'super_admin'], true)) {
            return response()->json(['message' => '却下権限がありません'], 403);
        }

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:1000'],
        ]);

        $invoice->approval_status  = 'rejected';
        $invoice->approval_comment = $validated['comment'];
        $invoice->approved         = false;
        $invoice->approved_at      = null;
        $invoice->approved_by      = null;
        $invoice->save();

        // 電子印は廃止 (2026-05-14)。PDF 再生成不要、フラグのみ更新。

        return response()->json(['invoice' => $invoice->fresh()->load('lines')]);
    }

    /**
     * DELETE /api/v1/invoices/{invoice}
     * draft / issued ともに削除可能（誤発行リカバリ用）。
     * 発行済の場合は Storage 上の PDF も併せて削除する。
     */
    public function destroy(Invoice $invoice): JsonResponse
    {
        // 承認済の書類は削除不可（PDF 再生成も同じく不可）
        if ($invoice->approved) {
            return response()->json([
                'message' => '承認済の書類は削除できません',
            ], 422);
        }
        if ($invoice->status === 'issued' && $invoice->pdf_path) {
            try {
                $this->storage->delete($invoice->pdf_path);
            } catch (\Throwable $e) {
                // Storage 削除失敗時もレコード削除は続行（孤児ファイル発生を許容）
                report($e);
            }
        }
        $invoice->delete();
        return response()->json(null, 204);
    }

    /**
     * POST /api/v1/invoices/{invoice}/duplicate
     *
     * 既存の見積/注文/請求から下書きを複写する。
     *   - doc_type は元と同じ
     *   - 採番は「対象データの翌月」で新規
     *     - year_month = 元の year_month の翌月
     *     - issued_date = 元の issued_date の翌月同日（月末日の overflow を避けるため
     *       addMonthNoOverflow を使う。例: 元=2026-01-31 → 2026-02-28）
     *     - 元 issued_date が null の場合は year_month 初日にフォールバック
     *   - 注文書は acknowledgement_no も同時採番
     *   - status=draft / approval_status=draft / 承認/PDF/受領メタは全リセット
     *   - 元データへのリンクは保持しない（quote_number 等の SES台帳フィードバックも行わない）
     *   - 明細は全件コピー（quantity / unit_price / amount / is_expense 維持）
     *   - due_date は元と同じ値をコピーしない（issued_date が変わるため null にして再入力）
     */
    public function duplicate(Invoice $invoice): JsonResponse
    {
        $customer = $invoice->customer;
        if (!$customer || empty($customer->invoice_code)) {
            throw ValidationException::withMessages([
                'customer_id' => ['取引先に invoice_code が設定されていないため複写できません'],
            ]);
        }

        // 対象データの翌月で採番する
        $yearMonth = \Carbon\Carbon::createFromFormat('Y-m', $invoice->year_month)
            ->addMonthNoOverflow()
            ->format('Y-m');
        $issuedDate = $invoice->issued_date
            ? \Carbon\Carbon::parse($invoice->issued_date)->addMonthNoOverflow()->toDateString()
            : \Carbon\Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth()->toDateString();

        // 当月の2ヶ月先以降の複写は禁止（誤って先々月分まで作らないため）
        // 許容: 計算後 year_month <= 当月+1
        $maxAllowedYm = now()->addMonthNoOverflow()->format('Y-m');
        if (strcmp($yearMonth, $maxAllowedYm) > 0) {
            throw ValidationException::withMessages([
                'year_month' => ['当月の2ヶ月先の複写はできません'],
            ]);
        }

        $kind = match ($invoice->doc_type) {
            'estimate'       => 'estimate',
            'purchase_order' => 'purchase_order',
            default          => 'invoice',
        };
        $newNumber = $this->numberService->generate($customer, $yearMonth, $kind);
        $newAckNo  = $invoice->doc_type === 'purchase_order'
            ? $this->numberService->generate($customer, $yearMonth, 'acknowledgement')
            : null;

        $new = $invoice->replicate(['id', 'created_at', 'updated_at']);
        $new->invoice_number           = $newNumber;
        $new->acknowledgement_no       = $newAckNo;
        $new->year_month               = $yearMonth;
        $new->issued_date              = $issuedDate;
        $new->due_date                 = null;
        $new->status                   = 'draft';
        $new->approval_status          = 'draft';
        $new->approved                 = false;
        $new->approved_at              = null;
        $new->approved_by              = null;
        $new->submitted_by             = null;
        $new->approval_comment         = null;
        $new->pdf_path                 = null;
        $new->acknowledgement_pdf_path = null;
        $new->save();

        foreach ($invoice->lines as $line) {
            InvoiceLine::create([
                'invoice_id'  => $new->id,
                'sort_order'  => $line->sort_order,
                'description' => $line->description,
                'quantity'    => $line->quantity,
                'unit'        => $line->unit,
                'unit_price'  => $line->unit_price,
                'tax_rate'    => $line->tax_rate,
                'amount'      => $line->amount,
                'is_expense'  => $line->is_expense,
            ]);
        }

        $new->load('lines');
        $new->recalcAmounts();
        $new->save();

        return response()->json($new->fresh()->load('lines'), 201);
    }
}
