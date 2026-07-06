<?php

namespace App\Console\Commands;

use App\Models\EngineerMailSource;
use App\Models\ProjectMailSource;
use App\Services\EngineerMailScoringService;
use App\Services\ProjectMailScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * HTML 専用配信メール（body_text=定型文だけで実体は body_html）の既存行を再スコアする。
 *
 * 本文選択を resolveBody() へ切替えた修正（HtmlOnlyMailBodyScoring）を本番既存行へ反映する
 * 一度きりの後処理。全件 rescoreAll（各10万件規模で無関係行まで書き戻す）を避け、
 * resolveBody が挙動を変える対象＝「body_text が薄い(<200) かつ body_html がリッチ(>1500)」の
 * 行だけを score() で採点し直す（本番実測 PMS 約4,600 / EMS 約6,800）。
 *
 *   php artisan mail-sources:rescore-thin-body-html                       # DRY-RUN（対象規模のみ）
 *   php artisan mail-sources:rescore-thin-body-html --execute
 *   php artisan mail-sources:rescore-thin-body-html --type=project --execute
 *
 * ⚠ score() は extract() も再実行するため、手編集済みの抽出情報は上書きされる。対象は
 *   未閲覧・除外圏の配信メールが大半で実害は小さいが、本番実行前に必ず DRY-RUN で規模確認。
 */
class RescoreThinBodyHtmlMails extends Command
{
    protected $signature = 'mail-sources:rescore-thin-body-html {--execute} {--type=both : project|engineer|both} {--limit=0}';
    protected $description = 'HTML専用配信メール（薄い本文+リッチHTML）の既存行を resolveBody で再スコア';

    private const THIN = 200;
    private const RICH = 1500;

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $type    = (string) $this->option('type');
        $limit   = (int) $this->option('limit');
        $this->info($execute ? 'モード: 更新' : 'モード: DRY-RUN（対象件数のみ・更新なし）');

        if (in_array($type, ['both', 'project'], true)) {
            $this->rescoreSet('PMS', ProjectMailSource::class, fn ($e) => app(ProjectMailScoringService::class)->score($e), $execute, $limit);
        }
        if (in_array($type, ['both', 'engineer'], true)) {
            $this->rescoreSet('EMS', EngineerMailSource::class, fn ($e) => app(EngineerMailScoringService::class)->score($e, false), $execute, $limit);
        }
        return self::SUCCESS;
    }

    private function rescoreSet(string $label, string $modelClass, callable $rescore, bool $execute, int $limit): void
    {
        $base = $modelClass::withoutGlobalScopes()
            ->whereNotNull('email_id')
            ->whereHas('email', fn ($q) => $q
                ->whereRaw('coalesce(length(body_text),0) < ?', [self::THIN])
                ->whereRaw('coalesce(length(body_html),0) > ?', [self::RICH]));

        $target = (clone $base)->count();
        $this->info("[{$label}] 対象（薄い本文+リッチHTML）: {$target}件");

        if (!$execute) {
            return;
        }

        $scanned = 0; $changed = 0; $visible = 0; $hidden = 0; $err = 0;
        $base->with('email')->orderBy('id')->chunkById(300, function ($rows) use ($rescore, $limit, &$scanned, &$changed, &$visible, &$hidden, &$err, $label) {
            foreach ($rows as $ms) {
                if ($limit > 0 && $scanned >= $limit) return false;
                $scanned++;
                if (!$ms->email) continue;
                $oldStatus = $ms->status;
                $oldScore  = (int) $ms->score;
                try {
                    $new = $rescore($ms->email);
                    if ((int) $new->score !== $oldScore || $new->status !== $oldStatus) $changed++;
                    if ($oldStatus === 'excluded' && $new->status !== 'excluded') $visible++;
                    if ($oldStatus !== 'excluded' && $new->status === 'excluded') $hidden++;
                } catch (\Throwable $e) {
                    $err++;
                    Log::warning("[rescore-thin-body-html][{$label}] id={$ms->id} " . $e->getMessage());
                }
            }
            $this->info("  scanned={$scanned} changed={$changed} 表示復活={$visible} 非表示化={$hidden} err={$err}");
            gc_collect_cycles();
            return true;
        });

        $this->info("[{$label}] 完了: scanned={$scanned} changed={$changed} 表示復活={$visible} 非表示化={$hidden} err={$err}");
        Log::info('rescore-thin-body-html', compact('label', 'scanned', 'changed', 'visible', 'hidden', 'err'));
    }
}
