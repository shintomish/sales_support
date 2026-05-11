<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;

/**
 * 請求書 PDF 生成サービス（Phase C）
 *
 * Blade テンプレートを HTML に展開し、Browsershot (Headless Chromium) で
 * PDF に変換 → Supabase Storage に保存する。
 */
class InvoicePdfService
{
    public function __construct(
        private readonly SupabaseStorageService $storage,
    ) {}

    /**
     * 請求書/見積書/注文書 PDF を生成して Storage に保存。
     * Invoice の pdf_path を更新して保存する。
     */
    public function generateAndStore(Invoice $invoice): string
    {
        $url = $this->renderAndUpload($invoice, false, $invoice->invoice_number);

        $invoice->pdf_path = $url;
        $invoice->save();

        return $url;
    }

    /**
     * 注文請書 PDF を生成して Storage に保存。
     * 同じ Invoice 行から、宛先・立場文言を反転した請書フォーマットで出力する。
     * 請書側は印不要（受領側が押印）のため、いずれの状態でも印は描画しない。
     */
    public function generateAcknowledgementAndStore(Invoice $invoice): string
    {
        if ($invoice->doc_type !== 'purchase_order') {
            throw new \RuntimeException('注文請書は注文書(doc_type=purchase_order)に対してのみ生成可能です');
        }
        if (empty($invoice->acknowledgement_no)) {
            throw new \RuntimeException('注文請書番号(acknowledgement_no)が未設定です');
        }

        $url = $this->renderAndUpload($invoice, true, $invoice->acknowledgement_no);

        $invoice->acknowledgement_pdf_path = $url;
        $invoice->save();

        return $url;
    }

    /**
     * 共通: blade を render → Browsershot で PDF → Storage アップロード。
     * $isAcknowledgement=true の場合は請書フォーマット（印影なし・宛先反転）。
     */
    private function renderAndUpload(Invoice $invoice, bool $isAcknowledgement, string $fileBaseName): string
    {
        $invoice->load('lines', 'customer');

        // 電子印は以下の場合のみ押印。
        //   - 見積書(estimate): 担当者ベース運用のため承認なしで常に角印を押印
        //   - 請求書/注文書: invoice.approved=true のときのみ押印
        //   - 注文請書(acknowledgement): 取引先押印欄を空けるため常に印なし
        $skipSeal = $isAcknowledgement
            || ($invoice->doc_type !== 'estimate' && !$invoice->approved);
        $invoiceForRender = clone $invoice;
        if ($skipSeal) {
            $invoiceForRender->issuer_round_seal_snapshot  = null;
            $invoiceForRender->issuer_square_seal_snapshot = null;
        }

        $html = View::make('invoices.pdf', [
            'invoice'           => $invoiceForRender,
            'isAcknowledgement' => $isAcknowledgement,
        ])->render();
        $binary = $this->htmlToPdf($html);

        $path = sprintf('invoices/%d/%s/%s.pdf',
            $invoice->tenant_id,
            $invoice->year_month,
            $fileBaseName,
        );

        return $this->storage->uploadBinary($binary, $path, 'application/pdf');
    }

    /**
     * 送付状 PDF をバイナリで返す（保存はしない）
     *
     * @param array<int, array{name:string,count:int}> $items 同封物リスト
     */
    public function renderCoverLetter(Invoice $invoice, array $items): string
    {
        $invoice->load('customer');
        $this->refreshIssuerFromTenant($invoice);
        $html = View::make('invoices.cover_letter', ['invoice' => $invoice, 'items' => $items])->render();
        return $this->htmlToPdf($html);
    }

    /**
     * 長3封筒 PDF をバイナリで返す（保存はしない）
     *
     * @param array<int,string> $zaichuLabels 朱印に表示する文言の配列。空配列なら朱印なし
     */
    public function renderEnvelope(Invoice $invoice, array $zaichuLabels = []): string
    {
        $invoice->load(['customer.primaryContact']);
        $this->refreshIssuerFromTenant($invoice);
        $html = View::make('invoices.envelope', [
            'invoice'      => $invoice,
            'zaichuLabels' => $zaichuLabels,
        ])->render();
        // 長3封筒: 235mm × 120mm（横向き）。Browsershot に明示指定
        return $this->htmlToCustomPdf($html, 235, 120);
    }

    /**
     * 送付状/封筒は運用書類（発行時の歴史的スナップショット不要）。
     * 最新のテナント設定で発行者情報を上書きする。
     */
    private function refreshIssuerFromTenant(Invoice $invoice): void
    {
        $tenant = \App\Models\Tenant::query()->find($invoice->tenant_id);
        if (!$tenant) return;

        $map = [
            'issuer_name_snapshot'           => 'invoice_issuer_name',
            'issuer_postal_code_snapshot'    => 'invoice_issuer_postal_code',
            'issuer_address_snapshot'        => 'invoice_issuer_address',
            'issuer_tel_snapshot'            => 'invoice_issuer_tel',
            'issuer_fax_snapshot'            => 'invoice_issuer_fax',
            'issuer_url_snapshot'            => 'invoice_issuer_url',
            'issuer_logo_snapshot'           => 'invoice_issuer_logo_path',
            'issuer_invoice_number_snapshot' => 'invoice_issuer_invoice_number',
        ];
        foreach ($map as $invoiceField => $tenantField) {
            $value = $tenant->{$tenantField} ?? null;
            if ($value !== null && $value !== '') {
                $invoice->{$invoiceField} = $value;
            }
        }
    }

    /**
     * 任意サイズで PDF 化（封筒など A4 以外）。サイズは mm 単位。
     */
    private function htmlToCustomPdf(string $html, float $widthMm, float $heightMm): string
    {
        $shot = Browsershot::html($html)
            ->paperSize($widthMm, $heightMm, 'mm')
            ->showBackground()
            ->margins(0, 0, 0, 0)
            ->noSandbox()
            ->setNodeEnv([
                'HOME'                => '/tmp',
                'PUPPETEER_CACHE_DIR' => '/var/www/.cache/puppeteer',
            ])
            ->setOption('args', [
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--single-process',
                '--no-zygote',
                '--disable-crash-reporter',
                '--disable-breakpad',
                '--user-data-dir=/tmp/chromium-data',
            ]);
        if ($chromePath = env('PUPPETEER_EXECUTABLE_PATH')) {
            $shot->setChromePath($chromePath);
        }
        return $shot->pdf();
    }

    /**
     * HTML から PDF バイナリを生成
     *
     * www-data ユーザーで Chromium を起動する場合、HOME に書き込めないと
     * crashpad の初期化で失敗する。--user-data-dir で書き込み可能な
     * ディレクトリを明示し、crash reporter を無効化する。
     */
    public function htmlToPdf(string $html): string
    {
        // PHP-FPM の www-data は HOME=/var/www で書き込み不可。
        // Chromium は HOME に crashpad のディレクトリを作ろうとして失敗するため、
        // Node プロセスの環境変数として明示的に HOME=/tmp を渡す。
        // また PHP-FPM ワーカーは Docker ENV を継承しないため、
        // PUPPETEER_CACHE_DIR を明示しないと puppeteer が Chromium を見つけられず
        // "Class not found" → Chromium 起動失敗になる。
        $shot = Browsershot::html($html)
            ->format('A4')
            ->showBackground()
            ->margins(0, 0, 0, 0)
            ->noSandbox()
            ->setNodeEnv([
                'HOME'                => '/tmp',
                'PUPPETEER_CACHE_DIR' => '/var/www/.cache/puppeteer',
            ])
            ->setOption('args', [
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--single-process',
                '--no-zygote',
                '--disable-crash-reporter',
                '--disable-breakpad',
                '--user-data-dir=/tmp/chromium-data',
            ]);

        // 環境変数で Chromium のパスが指定されている場合のみ明示
        if ($chromePath = env('PUPPETEER_EXECUTABLE_PATH')) {
            $shot->setChromePath($chromePath);
        }

        return $shot->pdf();
    }
}
