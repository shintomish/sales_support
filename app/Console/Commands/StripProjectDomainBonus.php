<?php

namespace App\Console\Commands;

use App\Models\ProjectMailSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 案件メールの既存スコアからドメイン信頼度(±20)を一度きりで除去する（domainBonus 廃止に伴う後処理）。
 *
 * 本文非依存（再スコアしない）:
 *  - score_reasons の `domain:host:±N(..)` から補正値 N を取り出し、score = clamp(score - N) に補正。
 *  - score_reasons から domain 行を除去、score_breakdown から 🏢 項目を除去。
 *  - status は自動判定(new/review/excluded)のみ再計算。手動運用状態(registered/proposing/working 等)は保持。
 *  - isExcluded 由来の除外行(score=0, reasons=['excluded'], domain 行なし)は対象外（domain 行が無いため）。
 *
 * 例:
 *   php artisan mail-sources:strip-domain-bonus              # DRY-RUN
 *   php artisan mail-sources:strip-domain-bonus --execute
 */
class StripProjectDomainBonus extends Command
{
    protected $signature = 'mail-sources:strip-domain-bonus {--execute} {--limit=0}';
    protected $description = '案件メールの既存スコアからドメイン信頼度(±20)を除去（score/reasons/breakdown/status補正・本文非依存）';

    private const SCORE_OK = 60;
    private const SCORE_REVIEW = 40;
    private const AUTO_STATUS = ['new', 'review', 'excluded'];

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $limit   = (int) $this->option('limit');
        $this->info($execute ? 'モード: 更新' : 'モード: DRY-RUN');

        $scanned = 0; $changed = 0; $visible = 0; $hidden = 0; $err = 0;

        ProjectMailSource::withoutGlobalScopes()
            ->where('score_reasons', 'like', '%domain:%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($execute, $limit, &$scanned, &$changed, &$visible, &$hidden, &$err) {
                foreach ($rows as $pms) {
                    if ($limit > 0 && $scanned >= $limit) return false;
                    $scanned++;
                    try {
                        $reasons = (array) ($pms->score_reasons ?? []);
                        $d = 0; $newReasons = []; $hadDomain = false;
                        foreach ($reasons as $r) {
                            if (is_string($r) && preg_match('/^domain:[^:]+:([+-]?\d+)\(/u', $r, $m)) {
                                $d += (int) $m[1]; $hadDomain = true; continue;
                            }
                            $newReasons[] = $r;
                        }
                        if (!$hadDomain) continue;

                        $oldStatus = $pms->status;
                        $newScore  = max(0, min(100, (int) $pms->score - $d));
                        $bd = array_values(array_filter(
                            (array) ($pms->score_breakdown ?? []),
                            fn ($x) => !(is_array($x) && isset($x['label']) && str_starts_with((string) $x['label'], '🏢'))
                        ));
                        // status は自動判定のみ再計算。手動運用状態は保持。
                        $status = in_array($oldStatus, self::AUTO_STATUS, true)
                            ? ($newScore === 0 ? 'excluded'
                                : ($newScore >= self::SCORE_OK ? 'new'
                                    : ($newScore >= self::SCORE_REVIEW ? 'review' : 'excluded')))
                            : $oldStatus;

                        $changed++;
                        if ($oldStatus === 'excluded' && $status !== 'excluded') $visible++;
                        if ($oldStatus !== 'excluded' && $status === 'excluded') $hidden++;

                        if ($execute) {
                            ProjectMailSource::withoutGlobalScopes()->whereKey($pms->id)->update([
                                'score'           => $newScore,
                                'score_reasons'   => array_values($newReasons),
                                'score_breakdown' => $bd,
                                'status'          => $status,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $err++;
                        Log::warning('[strip-domain-bonus] id=' . $pms->id . ' ' . $e->getMessage());
                    }
                }
                $this->info("  scanned={$scanned} changed={$changed} 表示復活={$visible} 非表示化={$hidden} err={$err}");
                gc_collect_cycles();
                return true;
            });

        $this->info("完了: scanned={$scanned} / changed={$changed} / 表示復活={$visible} / 非表示化={$hidden} / err={$err}（" . ($execute ? 'execute' : 'dry-run') . '）');
        Log::info('strip-domain-bonus', compact('execute', 'scanned', 'changed', 'visible', 'hidden', 'err'));
        return self::SUCCESS;
    }
}
