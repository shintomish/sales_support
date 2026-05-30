<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 捺印スキャンPDFのアップロード処理。
 *
 * フロー:
 *  - scan: アップロードファイルを一時保存 → OCR で帳票番号抽出 → 候補 Invoice 検索
 *  - uploadAndAttach: 一時ファイルを規約ファイル名で Supabase Storage (signed-scans) に保存 → Invoice 更新
 *
 * 上書き運用: 既存 signed_scan_pdf_path があれば削除してから新規アップロード。
 */
class SignedScanUploadService
{
    public const BUCKET = 'signed-scans';
    private const SUPPORTED_DOC_TYPES = ['invoice', 'purchase_order'];
    private const TMP_RELDIR = 'tmp/signed-scans';

    public function __construct(
        private readonly SignedScanOcrService $ocr,
        private readonly SupabaseStorageService $storage,
    ) {}

    /**
     * @return array{tmp_token: string, filename: string, detected_invoice_number: ?string, candidate_invoice: ?array}
     */
    public function scan(UploadedFile $file, int $tenantId): array
    {
        $tmpToken   = Str::uuid()->toString();
        $tmpDir     = storage_path('app/' . self::TMP_RELDIR);
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpAbsPath = $tmpDir . '/' . $tmpToken . '.pdf';
        $file->move($tmpDir, $tmpToken . '.pdf');

        // OCR
        $number = $this->ocr->extractInvoiceNumber($tmpAbsPath);
        Log::info('[SignedScanUpload] scan', [
            'filename' => $file->getClientOriginalName(),
            'detected' => $number,
        ]);

        // 候補 Invoice 検索 (tenant は BelongsToTenant の global scope に任せ、super_admin でも候補が出るようにする)
        $candidate = null;
        $nonApprovedHint = null; // 未承認状態で見つかった場合のヒント
        if ($number !== null) {
            $invoice = Invoice::query()
                ->whereIn('doc_type', self::SUPPORTED_DOC_TYPES)
                ->where('approval_status', 'approved')
                ->where(function ($q) use ($number) {
                    $q->where('invoice_number', $number)
                      ->orWhere('acknowledgement_no', $number);
                })
                ->first();
            Log::info('[SignedScanUpload] candidate lookup', [
                'number'    => $number,
                'found_id'  => $invoice?->id,
                'tenant_id' => $invoice?->tenant_id,
            ]);
            if ($invoice) {
                $candidate = $this->summarize($invoice);
            } else {
                // 承認済みで見つからなかった場合、未承認状態で存在するか確認
                $any = Invoice::query()
                    ->whereIn('doc_type', self::SUPPORTED_DOC_TYPES)
                    ->where(function ($q) use ($number) {
                        $q->where('invoice_number', $number)
                          ->orWhere('acknowledgement_no', $number);
                    })
                    ->first(['id', 'approval_status']);
                if ($any) {
                    $nonApprovedHint = [
                        'id'              => $any->id,
                        'approval_status' => $any->approval_status,
                    ];
                }
            }
        }

        return [
            'tmp_token'               => $tmpToken,
            'filename'                => $file->getClientOriginalName(),
            'detected_invoice_number' => $number,
            'candidate_invoice'       => $candidate,
            'non_approved_hint'       => $nonApprovedHint,
        ];
    }

    /**
     * 一時ファイルを確定して Storage に配置 + Invoice 更新。
     */
    public function uploadAndAttach(string $tmpToken, int $invoiceId, int $userId): array
    {
        $invoice = Invoice::query()->findOrFail($invoiceId);

        if (!in_array($invoice->doc_type, self::SUPPORTED_DOC_TYPES, true)) {
            throw new \DomainException('対象外の doc_type です (請求書/注文書のみ)');
        }
        if ($invoice->approval_status !== 'approved') {
            throw new \DomainException('承認済みの帳票のみ捺印スキャンを登録できます');
        }

        $tmpAbsPath = storage_path('app/' . self::TMP_RELDIR . '/' . $tmpToken . '.pdf');
        if (!file_exists($tmpAbsPath)) {
            throw new \DomainException('一時ファイルが見つかりません (tmp_token: ' . $tmpToken . ')');
        }

        $binary    = file_get_contents($tmpAbsPath);
        $yearMonth = str_replace('-', '', $invoice->year_month ?? now()->format('Y-m'));
        // Supabase Storage は object key に ASCII のみ許可。
        // Storage 上は帳票番号ベースのシンプル名、UI/DL 時は buildDownloadFilename() で日本語フル名を別途返す。
        $storageFilename = $this->buildStorageFilename($invoice);
        $storagePath = sprintf('%d/%s/%s', $invoice->tenant_id, $yearMonth, $storageFilename);

        // 旧ファイル削除 (上書き運用)
        if (!empty($invoice->signed_scan_pdf_path) && $invoice->signed_scan_pdf_path !== $storagePath) {
            try {
                $this->storage->deletePathFromBucket($invoice->signed_scan_pdf_path, self::BUCKET);
            } catch (\Throwable $e) {
                Log::warning('[SignedScanUpload] 旧ファイル削除失敗 (続行): ' . $e->getMessage());
            }
        }

        // 新ファイル upload (x-upsert: true なので同パスも上書き)
        $this->storage->uploadBinaryToBucket($binary, $storagePath, 'application/pdf', self::BUCKET);

        // Invoice 更新
        $invoice->update([
            'signed_scan_pdf_path'    => $storagePath,
            'signed_scan_uploaded_at' => now(),
            'signed_scan_uploaded_by' => $userId,
        ]);

        @unlink($tmpAbsPath);

        return [
            'invoice_id'           => $invoice->id,
            'signed_scan_pdf_path' => $storagePath,
            'filename'             => $this->buildDownloadFilename($invoice),
        ];
    }

    /**
     * フロント候補表示用に Invoice の最低限の情報を抜き出す。
     */
    public function summarize(Invoice $invoice): array
    {
        return [
            'id'                      => $invoice->id,
            'invoice_number'          => $invoice->invoice_number,
            'acknowledgement_no'      => $invoice->acknowledgement_no,
            'doc_type'                => $invoice->doc_type,
            'customer_name_snapshot'  => $invoice->customer_name_snapshot,
            'subject_name'            => $invoice->subject_name,
            'total'                   => $invoice->total,
            'issued_date'             => $invoice->issued_date?->toDateString(),
            'has_existing_signed_scan' => !empty($invoice->signed_scan_pdf_path),
        ];
    }

    /**
     * Storage object key 用の ASCII-only ファイル名。Supabase Storage は非ASCII を拒否するため。
     * 形式: INV-XXX-YYYYMM-NNN.pdf
     */
    private function buildStorageFilename(Invoice $invoice): string
    {
        $number = $invoice->invoice_number ?? ('UNKNOWN-' . $invoice->id);
        // ASCII 英数 + `-` のみ残し、それ以外を除去
        $number = preg_replace('/[^A-Za-z0-9\-]/', '', $number);
        return ($number !== '' ? $number : 'UNKNOWN-' . $invoice->id) . '.pdf';
    }

    /**
     * UI 表示 / ダウンロード時の Content-Disposition 用ファイル名 (日本語含む)。
     * 形式: INV-SIC-202606-001-シックコンピューター-新システム稼働.pdf
     */
    public function buildDownloadFilename(Invoice $invoice): string
    {
        $number   = $invoice->invoice_number ?? 'UNKNOWN';
        $customer = $this->sanitize($invoice->customer_name_snapshot ?? '', 30);
        $subject  = $this->sanitize($invoice->subject_name ?? '', 30);

        $parts = array_filter([$number, $customer, $subject], fn ($v) => $v !== '');
        return implode('-', $parts) . '.pdf';
    }

    /**
     * ファイル名安全化: 禁則文字を `_` に、空白除去、長さ truncate (マルチバイト対応)。
     */
    private function sanitize(string $s, int $maxLen): string
    {
        $s = preg_replace('/[\/\\\\:*?"<>|\r\n\t]/u', '_', $s);
        $s = preg_replace('/\s+/u', '_', $s);
        $s = preg_replace('/_+/u', '_', $s);
        $s = trim($s, '_');
        return mb_substr($s, 0, $maxLen, 'UTF-8');
    }

    /**
     * 一時ファイルの絶対パスを取得 (controller での cleanup 用)。
     */
    public function getTmpAbsolutePath(string $tmpToken): string
    {
        return storage_path('app/' . self::TMP_RELDIR . '/' . $tmpToken . '.pdf');
    }
}
