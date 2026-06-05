<?php

namespace App\Console\Commands;

use App\Models\ReportRecipient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Kagoya 配送遅延アラート (project_kagoya_gmail_delivery)。
 *
 * 直近1時間に「到着」したメールの平均配送遅延 (arrived_at - received_at) が
 * 閾値(既定120分)を超えたら、日次レポート配信先にメール通知する。
 * 配送遅延は Kagoya 側のキュー滞留で発生するため、悪化を早期に気づくための監視。
 *
 * - サンプルが少ない時間帯のノイズを避けるため、直近1h の到着が --min-count 未満なら判定しない。
 * - 連続通知を避けるため、テナント単位で once-per (既定3h) のクールダウン (cache)。
 *
 * 毎時実行を想定 (routes/console.php / 本番限定)。
 */
class CheckDeliveryDelay extends Command
{
    protected $signature = 'emails:check-delivery-delay
        {--threshold=120 : 平均遅延の閾値(分)}
        {--min-count=10 : 直近1hの最小到着件数(これ未満は判定しない)}
        {--cooldown=3 : 同一テナントへの再通知抑止(時間)}
        {--dry-run : 送信せず結果のみ表示}';

    protected $description = 'Kagoya 配送遅延が閾値超なら日次レポート配信先に通知';

    public function handle(): int
    {
        $threshold = max(1, (int) $this->option('threshold'));
        $minCount  = max(1, (int) $this->option('min-count'));
        $cooldownH = max(1, (int) $this->option('cooldown'));
        $dryRun    = (bool) $this->option('dry-run');

        // 通知対象テナント = 日次レポート配信先を持つテナント
        $tenantIds = ReportRecipient::withoutGlobalScopes()
            ->where('report_type', 'daily_delivery_report')
            ->where('is_active', true)
            ->distinct()->pluck('tenant_id')->all();

        foreach ($tenantIds as $tenantId) {
            $tenantId = (int) $tenantId;

            $row = DB::selectOne("
                SELECT count(*) n,
                  round(avg(extract(epoch from (arrived_at - received_at))/60))::int avg_min,
                  round(max(extract(epoch from (arrived_at - received_at))/60))::int max_min
                FROM public.emails
                WHERE tenant_id = ?
                  AND arrived_at > now() - interval '1 hour'
                  AND arrived_at IS NOT NULL AND received_at IS NOT NULL
                  AND arrived_at >= received_at", [$tenantId]);

            $n = (int) ($row->n ?? 0);
            $avg = (int) ($row->avg_min ?? 0);
            $this->line("tenant={$tenantId}: 直近1h 到着 {$n}件 / 平均遅延 {$avg}分");

            if ($n < $minCount || $avg <= $threshold) {
                Cache::forget("delivery_delay_alerted:{$tenantId}"); // 正常化したら抑止解除
                continue;
            }

            $cacheKey = "delivery_delay_alerted:{$tenantId}";
            if (Cache::has($cacheKey)) {
                $this->line('  クールダウン中 (通知抑止)');
                continue;
            }

            $recipients = ReportRecipient::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('report_type', 'daily_delivery_report')
                ->where('is_active', true)
                ->pluck('email')->all();
            if (empty($recipients)) continue;

            $maxMin  = (int) ($row->max_min ?? 0);
            $subject = "[警告] メール配送遅延 平均{$avg}分 (Kagoya 滞留の可能性)";
            $body = "直近1時間に到着したメールの配送遅延が閾値({$threshold}分)を超えています。\n\n"
                . "・到着件数: {$n}件\n"
                . "・平均遅延: {$avg}分 (約" . round($avg / 60, 1) . "時間)\n"
                . "・最大遅延: {$maxMin}分 (約" . round($maxMin / 60, 1) . "時間)\n\n"
                . "配送遅延は Kagoya 側のキュー滞留で発生します。取込自体は正常です。\n"
                . "詳細は `emails:delivery-delay-report` で確認できます。\n";

            if ($dryRun) {
                $this->warn("  [DRY-RUN] 通知対象 (宛先 " . count($recipients) . "名): {$subject}");
                continue;
            }

            foreach ($recipients as $email) {
                try {
                    Mail::raw($body, fn($m) => $m->to($email)->subject($subject));
                } catch (Throwable $e) {
                    Log::warning('delivery-delay alert send failed', ['email' => $email, 'err' => $e->getMessage()]);
                }
            }
            Cache::put($cacheKey, true, now()->addHours($cooldownH));
            Log::info("[Alert] 配送遅延通知 tenant={$tenantId} avg={$avg}min n={$n} → " . count($recipients) . '名');
            $this->info("  → 通知送信 ({$avg}分 / " . count($recipients) . '名)');
        }

        return self::SUCCESS;
    }
}
