<?php

namespace App\Services;

use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\Image;
use Illuminate\Support\Facades\Log;

/**
 * 捺印スキャンPDFから invoice_number / acknowledgement_no を OCR で抽出。
 *
 * 1. pdftoppm で PDF 1ページ目を PNG 化
 * 2. Vision API DOCUMENT_TEXT_DETECTION で全テキスト取得
 * 3. 正規表現で帳票番号 (INV/EST/ORD/UKE-XXX-YYYYMM-NNN) を抽出
 *
 * 失敗時は null (UI 側でユーザー手動選択にフォールバック)
 */
class SignedScanOcrService
{
    public function __construct(
        private readonly GoogleCredentialService $credentialService,
    ) {}

    /**
     * @return string|null マッチした帳票番号 (大文字に正規化)、推定失敗時 null
     */
    public function extractInvoiceNumber(string $pdfPath): ?string
    {
        $pngPath = null;
        try {
            $pngPath = $this->convertFirstPageToPng($pdfPath);
            if ($pngPath === null) {
                return null;
            }

            $text = $this->detectText(file_get_contents($pngPath));
            if ($text === null || $text === '') {
                return null;
            }

            return $this->matchInvoiceNumber($text);
        } catch (\Throwable $e) {
            Log::warning('[SignedScanOcr] 失敗: ' . $e->getMessage());
            return null;
        } finally {
            if ($pngPath !== null && file_exists($pngPath)) {
                @unlink($pngPath);
            }
        }
    }

    /**
     * pdftoppm で PDF の 1ページ目を PNG (200dpi) に変換し、PNG ファイルのパスを返す。
     */
    private function convertFirstPageToPng(string $pdfPath): ?string
    {
        $tmpDir    = sys_get_temp_dir();
        $prefix    = $tmpDir . '/signed_scan_' . bin2hex(random_bytes(8));
        $cmd       = sprintf(
            'pdftoppm -png -f 1 -l 1 -r 200 %s %s 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($prefix),
        );
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            Log::warning('[SignedScanOcr] pdftoppm failed: ' . implode("\n", $output));
            return null;
        }

        // pdftoppm は ${prefix}-1.png か ${prefix}-01.png を出す (zero-padding はページ桁数依存)
        foreach (['-1.png', '-01.png', '-001.png'] as $suffix) {
            $candidate = $prefix . $suffix;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Vision API DOCUMENT_TEXT_DETECTION でテキスト全文を取得。
     */
    private function detectText(string $imageBinary): ?string
    {
        $vision = new ImageAnnotatorClient([
            'credentials' => $this->credentialService->getCredentials(),
        ]);
        try {
            $feature      = (new Feature())->setType(Type::DOCUMENT_TEXT_DETECTION);
            $imageObj     = (new Image())->setContent($imageBinary);
            $annotateReq  = (new AnnotateImageRequest())->setImage($imageObj)->setFeatures([$feature]);
            $batchRequest = (new BatchAnnotateImagesRequest())->setRequests([$annotateReq]);
            $response     = $vision->batchAnnotateImages($batchRequest);
            $annotations  = $response->getResponses()[0];

            if ($annotations->hasError()) {
                Log::warning('[SignedScanOcr] Vision API error - ' . $annotations->getError()->getMessage());
                return null;
            }

            $fullText = $annotations->getFullTextAnnotation();
            return $fullText?->getText();
        } finally {
            $vision->close();
        }
    }

    /**
     * テキスト中から INV/EST/ORD/UKE-{invoice_code}-YYYYMM-NNN にマッチする番号を返す。
     * 複数ヒット時は INV/ORD/UKE 優先 (請求書/注文書/注文請書) → EST。
     * OCR ノイズ吸収のため大小文字無視・全角ハイフン正規化。
     */
    private function matchInvoiceNumber(string $text): ?string
    {
        // OCR 結果の正規化: 全角ハイフン/全角英数を半角化、改行ノイズ除去
        $normalized = mb_convert_kana($text, 'as');
        $normalized = str_replace(['ー', '−', '―'], '-', $normalized);

        $pattern = '/\b(INV|ORD|UKE|EST)-([A-Z0-9]{2,8})-(\d{6})-(\d{3,5})\b/i';
        if (!preg_match_all($pattern, $normalized, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $priority = ['INV' => 0, 'ORD' => 1, 'UKE' => 2, 'EST' => 3];
        usort($matches, function ($a, $b) use ($priority) {
            return ($priority[strtoupper($a[1])] ?? 99) <=> ($priority[strtoupper($b[1])] ?? 99);
        });
        return strtoupper($matches[0][0]);
    }
}
