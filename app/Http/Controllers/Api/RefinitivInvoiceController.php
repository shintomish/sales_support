<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Invoice;
use App\Services\InvoiceCreationService;
use App\Services\RefinitivPoParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Refinitiv (LSEG) 注文書 PDF を取り込んで請求書を発行する専用フロー
 *
 *  POST /api/v1/invoices/refinitiv/parse  - PDF を Claude API で構造化抽出（保存なし）
 *  POST /api/v1/invoices/refinitiv/issue  - 抽出済 PO データ + SES案件 から請求書ドラフト発行
 */
class RefinitivInvoiceController extends Controller
{
    public function __construct(
        private readonly RefinitivPoParserService $parser,
        private readonly InvoiceCreationService $creationService,
    ) {}

    /**
     * POST /api/v1/invoices/refinitiv/parse
     *
     * multipart/form-data:
     *   - file: Refinitiv が送ってきた注文書 PDF
     */
    public function parse(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        $tmp = $request->file('file')->getRealPath();
        $parsed = $this->parser->parse($tmp);

        // raw_text は容量が大きいのでクライアントには返さない
        unset($parsed['raw_text']);

        return response()->json($parsed);
    }

    /**
     * POST /api/v1/invoices/refinitiv/issue
     *
     * Body:
     *   deal_id        : 対象 SES契約 (deal_id)
     *   year_month     : YYYY-MM
     *   po_number      : 注文書番号（既存抽出結果を編集後に送る想定）
     *   vendor_metadata: その他の情報セクションのキー/値（自由形式 JSON）
     *   issued_date?   : 発行日（未指定なら年月末）
     */
    public function issue(Request $request): JsonResponse
    {
        $v = $request->validate([
            'deal_id'           => ['required', 'integer'],
            'year_month'        => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'po_number'         => ['required', 'string', 'max:50'],
            'vendor_metadata'   => ['nullable', 'array'],
            'issued_date'       => ['nullable', 'date'],
        ]);

        $deal = Deal::query()->findOrFail($v['deal_id']);

        // 同 deal × year_month に既に請求書がある場合はエラー
        $exists = Invoice::where('deal_id', $deal->id)
            ->where('year_month', $v['year_month'])
            ->where('doc_type', 'invoice')
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'deal_id' => ['この案件・年月の請求書は既に発行済みです'],
            ]);
        }

        $invoice = $this->creationService->createFromDeal($deal, $v['year_month'], [
            'issued_date'     => $v['issued_date'] ?? null,
            'order_number'    => $v['po_number'],
            'vendor_metadata' => $v['vendor_metadata'] ?? null,
            'language'        => 'en',
            // 注文書の品名行 (例: "Aizen - JBIC - Market data consulting Apr-Jun2026") を件名に転用
            'subject_name'    => $v['vendor_metadata']['description'] ?? null,
        ]);

        return response()->json($invoice->load('lines'), 201);
    }
}
