<?php

namespace App\Console\Commands;

use App\Models\EngineerMailSource;
use App\Models\ProjectMailSource;
use App\Services\EngineerMailScoringService;
use App\Services\ProjectMailScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 既存の案件/技術者メールに score_breakdown（営業向けスコア内訳）を後追いで埋める。
 *
 * 安全性:
 *  - **score_breakdown カラムのみ更新**。score/status/score_reasons/単価は一切変えない（可視性に影響なし）。
 *  - 内訳は calcScore を本文から再計算（点数ロジックと同一）。ドメイン加点は既存 score_reasons の
 *    `domain:...:+N(..)` から取り出して付与（domainBonus の再クエリを避ける）。
 *  - chunkById でメモリ一定。tinker(psysh) を使わないので ProcessForker OOM を起こさない。
 *
 * 例:
 *   php artisan mail-sources:backfill-score-breakdown --type=both            # DRY-RUN
 *   php artisan mail-sources:backfill-score-breakdown --type=both --execute
 *   php artisan mail-sources:backfill-score-breakdown --type=project --execute --limit=1000
 */
class BackfillScoreBreakdown extends Command
{
    protected $signature = 'mail-sources:backfill-score-breakdown
        {--type=both : project | engineer | both}
        {--execute : 実際に更新する（未指定は DRY-RUN）}
        {--limit=0 : 種別ごとの処理上限（0=無制限）}';

    protected $description = '既存の案件/技術者メールに score_breakdown(スコア内訳) を埋める（score_breakdown のみ更新・他は不変）';

    public function handle(ProjectMailScoringService $proj, EngineerMailScoringService $eng): int
    {
        $type    = (string) $this->option('type');
        $execute = (bool) $this->option('execute');
        $limit   = (int) $this->option('limit');
        $this->info($execute ? 'モード: 更新' : 'モード: DRY-RUN');

        if ($type === 'project' || $type === 'both') {
            $this->backfill('project', $execute, $limit, function ($row) use ($proj) {
                $email = $row->email;
                if (!$email) return null;
                $bd = $proj->calcScoreBreakdown($email);
                // 本文 purge 済み等で base 内訳が無い時は、ドメイン加点だけの誤解を招く部分内訳を作らず
                // null のままにする（フロントは score_reasons の理由ラベルにフォールバック）。
                if (count($bd) === 0) return null;
                return $this->appendDomain($bd, $row->score_reasons ?? []);
            });
        }
        if ($type === 'engineer' || $type === 'both') {
            $this->backfill('engineer', $execute, $limit, function ($row) use ($eng) {
                $email = $row->email;
                if (!$email) return null;
                // engineer は domain 加点なし
                return $eng->calcScoreBreakdown($email);
            });
        }
        return self::SUCCESS;
    }

    /** 既存 score_reasons の domain 行から内訳項目を付与（domainBonus を再クエリしない） */
    private function appendDomain(array $bd, array $reasons): array
    {
        foreach ($reasons as $r) {
            if (is_string($r) && preg_match('/^domain:(.+?):([+-]?\d+)\(/u', $r, $m)) {
                $bd[] = ['label' => "🏢 ドメイン信頼度（{$m[1]}）", 'points' => (int) $m[2]];
            }
        }
        return $bd;
    }

    private function backfill(string $kind, bool $execute, int $limit, callable $compute): void
    {
        $model = $kind === 'project' ? ProjectMailSource::class : EngineerMailSource::class;
        $scanned = 0; $filled = 0; $empty = 0; $err = 0;

        // 本文(text/html)が残る行のみ対象（purge 済みは内訳を作れないので SQL で除外）。新しい順に処理。
        $model::withoutGlobalScopes()
            ->whereNull('score_breakdown')
            ->whereHas('email', function ($q) {
                $q->where(fn ($q2) => $q2->whereNotNull('body_text')->where('body_text', '<>', ''))
                  ->orWhere(fn ($q2) => $q2->whereNotNull('body_html')->where('body_html', '<>', ''));
            })
            ->with('email')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($compute, $execute, $limit, &$scanned, &$filled, &$empty, &$err, $kind) {
                foreach ($rows as $row) {
                    if ($limit > 0 && $scanned >= $limit) return false;
                    $scanned++;
                    try {
                        $bd = $compute($row);
                        if ($bd === null || count($bd) === 0) { $empty++; continue; }
                        $filled++;
                        if ($execute) {
                            (get_class($row))::withoutGlobalScopes()
                                ->whereKey($row->getKey())
                                ->update(['score_breakdown' => $bd]);
                        }
                    } catch (\Throwable $e) {
                        $err++;
                        Log::warning("[backfill-score-breakdown:{$kind}] id={$row->getKey()} " . $e->getMessage());
                    }
                }
                $this->info("  [{$kind}] scanned={$scanned} filled={$filled} empty={$empty} err={$err}");
                gc_collect_cycles();
                return true;
            });

        $this->info("完了[{$kind}]: scanned={$scanned} / filled={$filled} / empty(本文なし等)={$empty} / err={$err}");
        Log::info("backfill-score-breakdown:{$kind}", compact('execute', 'scanned', 'filled', 'empty', 'err'));
    }
}
