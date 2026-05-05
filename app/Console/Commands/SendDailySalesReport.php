<?php

namespace App\Console\Commands;

use App\Mail\DailyReport;
use App\Models\ReportRecipient;
use App\Models\Tenant;
use App\Services\DailyReportBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 朝の日次レポート配信
 *   php artisan report:daily-sales              全テナント・実送信
 *   php artisan report:daily-sales --dry-run    送信せずにログ出力のみ
 *   php artisan report:daily-sales --tenant=1   特定テナントのみ
 */
class SendDailySalesReport extends Command
{
    protected $signature   = 'report:daily-sales {--tenant= : 特定 tenant_id のみ} {--dry-run : 送信しない}';
    protected $description = '朝の日次レポートを配信先にメール送信する';

    public function handle(DailyReportBuilder $builder): int
    {
        $tenantOnly = $this->option('tenant');
        $dryRun     = (bool) $this->option('dry-run');

        $tenants = Tenant::query()
            ->when($tenantOnly, fn ($q) => $q->where('id', $tenantOnly))
            ->where('is_active', true)
            ->get();

        if ($tenants->isEmpty()) {
            $this->warn('対象テナントがありません');
            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $this->processTenant($builder, $tenant->id, $dryRun);
        }

        return self::SUCCESS;
    }

    private function processTenant(DailyReportBuilder $builder, int $tenantId, bool $dryRun): void
    {
        $recipients = ReportRecipient::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('report_type', 'daily_sales')
            ->where('is_active', true)
            ->pluck('email')
            ->all();

        if (empty($recipients)) {
            $this->line("tenant_id={$tenantId}: 配信先なし（スキップ）");
            return;
        }

        try {
            $data = $builder->build($tenantId);
        } catch (Throwable $e) {
            Log::error('DailyReport build failed', ['tenant_id' => $tenantId, 'err' => $e->getMessage()]);
            $this->error("tenant_id={$tenantId}: build失敗 - {$e->getMessage()}");
            return;
        }

        $this->line(sprintf(
            "tenant_id=%d: 受信者 %d名 / 要対応 %d件 / AIサマリ %s",
            $tenantId,
            count($recipients),
            $data['action_total'] ?? 0,
            !empty($data['ai_summary']) ? 'あり' : 'なし',
        ));

        if ($dryRun) {
            $this->info('  [DRY-RUN] 送信せずに終了');
            return;
        }

        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(new DailyReport($data));
                $this->info("  → sent: {$email}");
            } catch (Throwable $e) {
                Log::warning('DailyReport send failed', [
                    'tenant_id' => $tenantId,
                    'email'     => $email,
                    'err'       => $e->getMessage(),
                ]);
                $this->error("  × failed: {$email} ({$e->getMessage()})");
            }
        }
    }
}
