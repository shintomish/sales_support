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
     * GET /api/v1/invoices/{invoice}/mail-template
     * メール送信モーダル用のテンプレート（subject/body）を返す。
     * テナントに保存されたテンプレがあればそれを使い、無ければデフォルトを使用。
     * プレースホルダ {invoice_number} {customer_name} {year_month} {total} {due_date} を置換する。
     */
    public function mailTemplate(Invoice $invoice): JsonResponse
    {
        $tenant = \App\Models\Tenant::find($invoice->tenant_id);
        $defaultSubject = '【請求書】{invoice_number}({year_month}分)';
        $defaultBody = "{customer_name} 様\n\n"
            . "いつもお世話になっております。\n"
            . "{year_month}分の請求書を添付にてお送りいたします。\n\n"
            . "請求番号: {invoice_number}\n"
            . "請求金額: ￥{total}（税込）\n"
            . "お支払期限: {due_date}\n\n"
            . "ご確認のほど、何卒よろしくお願い申し上げます。\n";

        $subject = $tenant?->invoice_email_subject_template ?: $defaultSubject;
        $body    = $tenant?->invoice_email_body_template    ?: $defaultBody;

        // ログインユーザーのメール署名を末尾に付加
        $user = \Illuminate\Support\Facades\Auth::user();
        $tpl  = $user ? \App\Models\EmailBodyTemplate::where('tenant_id', $invoice->tenant_id)
            ->where('user_id', $user->id)->first() : null;
        $signatureLines = ['', '--'];
        if ($tenant?->invoice_issuer_name)        $signatureLines[] = $tenant->invoice_issuer_name;
        if ($tpl?->name ?? $user?->name)          $signatureLines[] = $tpl?->name ?? $user?->name;
        if ($tpl?->department)                    $signatureLines[] = $tpl->department;
        if ($tpl?->position)                      $signatureLines[] = $tpl->position;
        if ($tenant?->invoice_issuer_postal_code) $signatureLines[] = '〒' . $tenant->invoice_issuer_postal_code . '　' . $tenant->invoice_issuer_address;
        $tel = $tpl?->mobile ?: $tenant?->invoice_issuer_tel;
        if ($tel)                                 $signatureLines[] = 'TEL：' . $tel;
        if ($tenant?->invoice_issuer_fax)         $signatureLines[] = 'FAX：' . $tenant->invoice_issuer_fax;
        $emailAddr = $tpl?->email ?: $user?->email;
        if ($emailAddr)                           $signatureLines[] = 'E-Mail：' . $emailAddr;
        if ($tenant?->invoice_issuer_url)         $signatureLines[] = $tenant->invoice_issuer_url;
        $signature = implode("\n", $signatureLines);
        $body = $body . "\n" . $signature . "\n";

        $invoice->load('customer:id,company_name,primary_contact_id,invoice_delivery_method', 'customer.contacts:id,customer_id,name,email');

        $vars = [
            '{invoice_number}' => $invoice->invoice_number,
            '{customer_name}'  => $invoice->customer_name_snapshot ?? $invoice->customer?->company_name ?? '',
            '{year_month}'     => $invoice->year_month,
            '{total}'          => number_format((float) $invoice->total),
            '{due_date}'       => $invoice->due_date?->format('Y年n月j日') ?? '',
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
            'attach_cover_letter'    => ['nullable', 'boolean'],
            'cover_items'            => ['nullable', 'array'],
            'cover_items.invoice'    => ['nullable', 'boolean'],
            'cover_items.timesheet'  => ['nullable', 'boolean'],
            'cover_items.transport'  => ['nullable', 'boolean'],
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

        // 送付状を生成して添付
        if (!empty($v['attach_cover_letter'])) {
            $items = [];
            $ci = $v['cover_items'] ?? [];
            if (!empty($ci['invoice']))   $items[] = ['name' => '御請求書',   'count' => 1];
            if (!empty($ci['timesheet'])) $items[] = ['name' => '勤務表',     'count' => 1];
            if (!empty($ci['transport'])) $items[] = ['name' => '交通費明細書', 'count' => 1];
            if (empty($items)) $items[] = ['name' => '御請求書', 'count' => 1];

            try {
                $bin = $this->pdfService->renderCoverLetter($invoice, $items);
                $name = '送付状-' . $invoice->invoice_number . '.pdf';
                $attachments[] = ['name' => $name, 'content' => $bin, 'mime' => 'application/pdf'];
                $metaNames[] = $name;
            } catch (\Throwable $e) { report($e); }
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
     * GET /api/v1/invoices/{invoice}/cover-letter?invoice=1&timesheet=0&transport=0
     * 送付状 PDF をインラインで返す
     */
    public function coverLetter(Request $request, Invoice $invoice)
    {
        $items = [];
        if ($request->boolean('invoice', true))   $items[] = ['name' => '御請求書',   'count' => 1];
        if ($request->boolean('timesheet'))       $items[] = ['name' => '勤務表',     'count' => 1];
        if ($request->boolean('transport'))       $items[] = ['name' => '交通費明細書', 'count' => 1];

        if (empty($items)) {
            return response()->json(['message' => '同封物が選択されていません'], 422);
        }

        $pdf = $this->pdfService->renderCoverLetter($invoice, $items);
        return response($pdf, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="cover-letter-' . $invoice->invoice_number . '.pdf"');
    }

    /**
     * GET /api/v1/invoices/{invoice}/envelope?zaichu=1
     * 長3封筒 PDF をインラインで返す
     *  - zaichu=1（既定）: 「請求書在中」朱印あり
     *  - zaichu=0       : 一般用途（朱印なし）
     */
    public function envelope(Request $request, Invoice $invoice)
    {
        $withZaichu = $request->boolean('zaichu', true);
        $pdf = $this->pdfService->renderEnvelope($invoice, $withZaichu);
        return response($pdf, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="envelope-' . $invoice->invoice_number . '.pdf"');
    }

    /**
     * POST /api/v1/invoices/{invoice}/approve
     * 承認 → 電子印付き PDF を再生成
     * tenant_admin / super_admin のみ実行可
     */
    public function approve(Invoice $invoice): JsonResponse
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if (!$user || !in_array($user->role ?? null, ['tenant_admin', 'super_admin'], true)) {
            return response()->json(['message' => '承認権限がありません'], 403);
        }

        $invoice->approved    = true;
        $invoice->approved_at = now();
        $invoice->approved_by = $user->id;
        $invoice->save();

        $url = $this->pdfService->generateAndStore($invoice);

        return response()->json([
            'pdf_url' => $url,
            'invoice' => $invoice->fresh()->load('lines'),
        ]);
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
