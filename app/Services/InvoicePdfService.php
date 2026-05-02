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
     * 請求書から PDF を生成して Storage に保存。
     * Invoice の pdf_path を更新して保存する。
     */
    public function generateAndStore(Invoice $invoice): string
    {
        $invoice->load('lines', 'customer');

        $html = View::make('invoices.pdf', ['invoice' => $invoice])->render();
        $binary = $this->htmlToPdf($html);

        // ファイルパス: invoices/{tenant_id}/{year_month}/{invoice_number}.pdf
        $path = sprintf('invoices/%d/%s/%s.pdf',
            $invoice->tenant_id,
            $invoice->year_month,
            $invoice->invoice_number,
        );

        $url = $this->storage->uploadBinary($binary, $path, 'application/pdf');

        $invoice->pdf_path = $url;
        $invoice->save();

        return $url;
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
        $shot = Browsershot::html($html)
            ->format('A4')
            ->showBackground()
            ->margins(0, 0, 0, 0)
            ->noSandbox()
            ->setNodeEnv(['HOME' => '/tmp'])
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
