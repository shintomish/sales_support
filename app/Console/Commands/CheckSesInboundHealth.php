<?php

namespace App\Console\Commands;

use App\Models\ReportRecipient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * SES Inbound 経路 死活監視 (project_ses_inbound_blight / Phase 2 前提)。
 *
 * SES→S3→Lambda→CF→/api/v1/inbound/email の全リンクが静かに壊れても、IMAP バックアップが
 * 後追いで穴埋めするため emails テーブルからは障害が見えにくい (ses- 行は IMAP 追いつき時に
 * imap- へ上書きされ消える)。そこで InboundEmailController が 200 を返すたびに更新する
 * ハートビート Cache 値 `inbound:ses:last_ok_at` を読み、「メールは流れているのに SES だけ
 * 古い」状態を検知して日次レポート配信先に通知する。
 *
 * 誤報を避ける設計:
 *   - トラフィック自己ゲート: 直近 --traffic-window 時間の取込が --min-count 未満なら判定しない
 *     (夜間・休日の無メール時に鳴らさない)。IMAP は 10 分毎に動くので SES 障害時もこの件数は正。
 *   - ハートビート未設定 (deploy 直後等) は「不明」として鳴らさない。
 *   - 連続通知を avoid するため --cooldown 時間のクールダウン (cache)。正常化で解除。
 *
 * 限界: IMAP と SES が同時に死ぬ全断時はトラフィック 0 で鳴らない (別次元の障害)。
 *
 * 毎時実行を想定 (routes/console.php / 本番限定)。Phase 2 (IMAP 低頻度化) の前提となる安全網。
 */
class CheckSesInboundHealth extends Command
{
    protected $signature = 'emails:check-ses-health
        {--threshold=120 : ハートビートがこの分数より古ければ異常候補}
        {--traffic-window=2 : トラフィック判定の遡及時間(時間)}
        {--min-count=10 : 上記窓内の最小取込件数(これ未満は判定しない)}
        {--cooldown=3 : 再通知抑止(時間)}
        {--dry-run : 送信せず結果のみ表示}';

    protected $description = 'SES Inbound 経路のハートビートが古い(=経路ダウン疑い)なら日次レポート配信先に通知';

    public function handle(): int
    {
        $thresholdMin = max(1, (int) $this->option('threshold'));
        $windowH      = max(1, (int) $this->option('traffic-window'));
        $minCount     = max(1, (int) $this->option('min-count'));
        $cooldownH    = max(1, (int) $this->option('cooldown'));
        $dryRun       = (bool) $this->option('dry-run');

        $tenantId = (int) config('services.inbound.tenant_id', 1);

        // ── トラフィック有無 (自己ゲート) ── IMAP/SES 問わず直近窓の取込件数
        $traffic = (int) (DB::selectOne("
            SELECT count(*) n FROM public.emails
            WHERE tenant_id = ?
              AND created_at > now() - (? || ' hours')::interval", [$tenantId, $windowH])->n ?? 0);

        // ── SES ハートビート ──
        $lastOkRaw = Cache::get('inbound:ses:last_ok_at');
        $lastOk    = $lastOkRaw ? Carbon::parse($lastOkRaw) : null;
        $staleMin  = $lastOk ? (int) round($lastOk->diffInSeconds(now()) / 60) : null;

        $this->line(sprintf(
            'tenant=%d: 直近%dh取込 %d件 / SESハートビート %s',
            $tenantId,
            $windowH,
            $traffic,
            $lastOk ? "{$staleMin}分前 ({$lastOk->toIso8601String()})" : '未設定'
        ));

        $cacheKey = "ses_health_alerted:{$tenantId}";

        // 判定: トラフィックが流れていて、かつハートビートが閾値より古い (or 未設定だがトラフィック有)
        $trafficFlowing = $traffic >= $minCount;
        $heartbeatStale = $lastOk === null ? false : ($staleMin > $thresholdMin);

        if (!$trafficFlowing) {
            $this->line('  トラフィック僅少 → 判定スキップ');
            Cache::forget($cacheKey);
            return self::SUCCESS;
        }
        if ($lastOk === null) {
            // ハートビート未設定: deploy 直後/初回。誤報回避のため鳴らさず WARNING ログのみ。
            $this->warn('  ハートビート未設定 (経路未疎通 or cache 初期化)。通知はしないがログに残す。');
            Log::warning('[SesHealth] ハートビート未設定でトラフィック有', ['tenant' => $tenantId, 'traffic' => $traffic]);
            return self::SUCCESS;
        }
        if (!$heartbeatStale) {
            Cache::forget($cacheKey); // 正常化で抑止解除
            $this->info('  正常 (SES 経路 稼働中)');
            return self::SUCCESS;
        }

        // ── ここから異常 (トラフィック有 & ハートビート古い) ──
        if (Cache::has($cacheKey)) {
            $this->line('  クールダウン中 (通知抑止)');
            return self::SUCCESS;
        }

        $recipients = ReportRecipient::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('report_type', 'daily_delivery_report')
            ->where('is_active', true)
            ->pluck('email')->all();

        $subject = "[警告] SES Inbound 経路 ダウン疑い (ハートビート {$staleMin}分前)";
        $body = "SES 受信経路 (B-light) のハートビートが {$thresholdMin}分以上更新されていません。\n\n"
            . "・最終疎通: {$lastOk->toIso8601String()} ({$staleMin}分前)\n"
            . "・直近{$windowH}h の取込: {$traffic}件 (メール自体は流れている)\n\n"
            . "メールは届いているのに SES 経路だけ止まっている可能性が高い状態です。\n"
            . "IMAP バックアップ (10分毎) が取込を継続しているため取りこぼしはありませんが、\n"
            . "受信鮮度が Kagoya 配送遅延ぶん悪化します。以下を確認してください:\n"
            . "  - AWS Lambda ses-inbound-forward の CloudWatch エラー\n"
            . "  - Cloudflare WAF ルール ses-inbound-skip-bot の有効性\n"
            . "  - nginx access log の /api/v1/inbound/email (401/5xx)\n";

        if ($dryRun) {
            $this->warn('  [DRY-RUN] 通知対象 (宛先 ' . count($recipients) . "名): {$subject}");
            return self::SUCCESS;
        }

        if (empty($recipients)) {
            $this->warn('  宛先なし (ReportRecipient 未登録)。ログのみ。');
            Log::warning('[SesHealth] 経路ダウン疑いだが通知宛先なし', ['tenant' => $tenantId, 'stale_min' => $staleMin]);
            return self::SUCCESS;
        }

        foreach ($recipients as $email) {
            try {
                Mail::raw($body, fn($m) => $m->to($email)->subject($subject));
            } catch (Throwable $e) {
                Log::warning('ses-health alert send failed', ['email' => $email, 'err' => $e->getMessage()]);
            }
        }
        Cache::put($cacheKey, true, now()->addHours($cooldownH));
        Log::warning("[Alert] SES経路ダウン疑い tenant={$tenantId} stale={$staleMin}min traffic={$traffic} → " . count($recipients) . '名');
        $this->info("  → 通知送信 ({$staleMin}分前 / " . count($recipients) . '名)');

        return self::SUCCESS;
    }
}
