<?php

namespace App\Console\Commands;

use App\Models\ProjectMailSource;
use App\Services\ProjectMailScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 全角数字「７５万円」等を取りこぼして unit_price が 0/null になっている既存案件メールの単価のみ再抽出する。
 *
 * 背景: ProjectMailScoringService::extract() の \d ベース正規表現が全角数字に非マッチで、
 * 「□単金：７５万円」のような案件が単価 0/null になっていた（2026-06-22 修正・mb_convert_kana 'n' 追加）。
 * 本コマンドはコード修正後の extract() で **単価カラムのみ** を再計算して上書きする。
 * score / status / その他抽出値には一切触れない（運用中の順位・状態を動かさないため）。
 *
 * 対象 = source=imap かつ (unit_price_max IS NULL OR <= 0) かつ 本文が残っている行。
 * 本文 purge 済み(body_text NULL)行は extract が null を返すので結果的にスキップ（誤上書きしない）。
 *
 * 例:
 *   php artisan project-mails:backfill-price                  # DRY-RUN（更新せず件数のみ）
 *   php artisan project-mails:backfill-price --execute        # 単価のみ更新
 *   php artisan project-mails:backfill-price --execute --limit=500
 */
class BackfillProjectMailPrice extends Command
{
    protected $signature = 'project-mails:backfill-price
        {--execute : 実際に更新する（未指定は DRY-RUN）}
        {--limit=0 : 処理上限（0=無制限）}';

    protected $description = '全角単価で取りこぼした既存案件メールの unit_price のみを再抽出して更新する（score/status 不変）';

    public function handle(ProjectMailScoringService $scoring): int
    {
        $execute = (bool) $this->option('execute');
        $limit   = (int) $this->option('limit');

        $this->info($execute ? 'モード: 単価のみ更新' : 'モード: DRY-RUN（更新しません）');

        $query = ProjectMailSource::withoutGlobalScopes()
            ->where('source', 'imap')
            ->where(function ($q) {
                $q->whereNull('unit_price_max')->orWhere('unit_price_max', '<=', 0);
            })
            ->whereHas('email', fn ($e) => $e->whereNotNull('body_text'))
            ->with('email');

        $scanned = 0;
        $updated = 0;

        $query->orderBy('id')->chunkById(500, function ($rows) use ($scoring, $execute, $limit, &$scanned, &$updated) {
            foreach ($rows as $pms) {
                if ($limit > 0 && $scanned >= $limit) return false;
                $scanned++;

                $data = $scoring->extract($pms->email);
                $min  = $data['unit_price_min'] ?? null;
                $max  = $data['unit_price_max'] ?? null;

                // 再抽出でも正の単価が取れなければ何もしない（誤って 0 で上書きしない）
                if (($min ?? 0) <= 0 && ($max ?? 0) <= 0) continue;

                $updated++;
                if ($execute) {
                    // 単価カラムのみ更新（updated_at も触らないよう timestamps 抑止）
                    ProjectMailSource::withoutGlobalScopes()->whereKey($pms->id)->update([
                        'unit_price_min' => $min,
                        'unit_price_max' => $max,
                    ]);
                }
                if ($updated <= 20) {
                    $this->line("  #{$pms->id}: min={$min} max={$max}  ({$pms->title})");
                }
            }
            $this->info("  ... scanned={$scanned} updated={$updated}");
            return true;
        });

        $this->info("完了: scanned={$scanned} / " . ($execute ? "updated={$updated}" : "would-update={$updated}"));
        Log::info('project-mails:backfill-price', compact('execute', 'scanned', 'updated'));

        return self::SUCCESS;
    }
}
