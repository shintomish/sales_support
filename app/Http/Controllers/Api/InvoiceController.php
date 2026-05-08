<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\InvoiceCreationService;
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
        private readonly SupabaseStorageService $storage,
    ) {}

    /** GET /api/v1/invoices */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year_month'  => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'customer_id' => ['nullable', 'integer'],
            'status'      => ['nullable', 'in:draft,issued'],
            'q'           => ['nullable', 'string', 'max:200'],
        ]);

        $query = Invoice::with(['customer:id,company_name', 'deal:id,title'])
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

        // 同 deal × year_month で既に存在する場合はエラー
        $exists = Invoice::where('deal_id', $deal->id)
            ->where('year_month', $validated['year_month'])
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

    /** GET /api/v1/invoices/{invoice} */
    public function show(Invoice $invoice): JsonResponse
    {
        return response()->json($invoice->load(['lines', 'customer:id,company_name', 'deal:id,title']));
    }

    /** PUT /api/v1/invoices/{invoice}  - メタ情報 + 明細を一括更新 */
    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'issued_date'                 => ['nullable', 'date'],
            'due_date'                    => ['nullable', 'date'],
            'notes'                       => ['nullable', 'string', 'max:2000'],
            'status'                      => ['nullable', 'in:draft,issued'],
            'order_number'                => ['nullable', 'string', 'max:100'],
            'quote_number'                => ['nullable', 'string', 'max:100'],
            'subject_name'                => ['nullable', 'string', 'max:255'],
            'work_period_text'            => ['nullable', 'string', 'max:100'],
            'work_location'               => ['nullable', 'string', 'max:255'],
            'delivery_items_text'         => ['nullable', 'string', 'max:255'],
            'transportation_note_text'    => ['nullable', 'string', 'max:1000'],
            'delivery_date_text'          => ['nullable', 'string', 'max:100'],
            'delivery_place_text'         => ['nullable', 'string', 'max:100'],
            'payment_terms_text'          => ['nullable', 'string', 'max:100'],
            'lines'                       => ['nullable', 'array'],
            'lines.*.description'         => ['required_with:lines', 'string', 'max:500'],
            'lines.*.quantity'            => ['required_with:lines', 'numeric'],
            'lines.*.unit'                => ['nullable', 'string', 'max:20'],
            'lines.*.unit_price'          => ['required_with:lines', 'numeric'],
            'lines.*.tax_rate'            => ['required_with:lines', 'numeric', 'in:0,0.08,0.10'],
            'lines.*.is_expense'          => ['nullable', 'boolean'],
        ]);

        $metaKeys = [
            'issued_date', 'due_date', 'notes', 'status',
            'order_number', 'quote_number', 'subject_name', 'work_period_text',
            'work_location', 'delivery_items_text', 'transportation_note_text',
            'delivery_date_text', 'delivery_place_text', 'payment_terms_text',
        ];
        $invoice->fill(array_intersect_key($validated, array_flip($metaKeys)));

        if (array_key_exists('lines', $validated)) {
            $invoice->lines()->delete();
            foreach (array_values($validated['lines']) as $i => $line) {
                InvoiceLine::query()->create([
                    'invoice_id'  => $invoice->id,
                    'sort_order'  => $i,
                    'description' => $line['description'],
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
     */
    public function generatePdf(Invoice $invoice): JsonResponse
    {
        $url = $this->pdfService->generateAndStore($invoice);
        if ($invoice->status === 'draft') {
            $invoice->status = 'issued';
            $invoice->save();
        }
        return response()->json(['pdf_url' => $url, 'invoice' => $invoice->fresh()->load('lines')]);
    }

    /**
     * DELETE /api/v1/invoices/{invoice}
     * draft / issued ともに削除可能（誤発行リカバリ用）。
     * 発行済の場合は Storage 上の PDF も併せて削除する。
     */
    public function destroy(Invoice $invoice): JsonResponse
    {
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
}
