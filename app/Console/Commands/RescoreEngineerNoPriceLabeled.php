<?php

namespace App\Console\Commands;

use App\Models\EngineerMailSource;
use App\Services\EngineerMailScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 希望単価が「単金額：85万円」「【単金】60万」等のラベルで書かれているのに旧正規表現の穴で
 * no_unit_price（review 止まり）になっていた技術者行を再スコアする一度きりの後処理。
 *
 * 抽出強化（extractUnitPrice に 単金額/単価額 の末尾「額」と 【単金】ブラケットを追加）を
 * 本番既存行へ反映する。対象は現在 no_unit_price かつ本文に該当ラベルを含む行に絞る
 * （本番実測 約2,175件）。score() 経由なので単価が採れれば no_unit_price が外れ、
 * 35万未満なら unit_price_too_low で正当に除外、それ以外はスコアで new/review に再判定。
 *
 *   php artisan mail-sources:rescore-engineer-no-price          # DRY-RUN（対象件数のみ）
 *   php artisan mail-sources:rescore-engineer-no-price --execute
 */
class RescoreEngineerNoPriceLabeled extends Command
{
    protected $signature = 'mail-sources:rescore-engineer-no-price {--execute} {--limit=0}';
    protected $description = '単金額/【単金】ラベルで no_unit_price になっていた技術者行を再スコア（単価抽出強化の反映）';

    // 新規対応ラベルを本文に含む行だけを対象化（PostgreSQL 正規表現）
    private const LABEL_RE = '単[価金]額|【\s*単[　 ]*金';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $limit   = (int) $this->option('limit');
        $this->info($execute ? 'モード: 更新' : 'モード: DRY-RUN（対象件数のみ・更新なし）');

        $base = EngineerMailSource::withoutGlobalScopes()
            ->whereNotNull('email_id')
            ->whereJsonContains('score_reasons', 'no_unit_price')
            ->whereHas('email', fn ($e) => $e->where(fn ($q) => $q
                ->where('body_html', '~', self::LABEL_RE)
                ->orWhere('body_text', '~', self::LABEL_RE)));

        $target = (clone $base)->count();
        $this->info("対象（no_unit_price かつ 単金額/【単金】ラベル含む）: {$target}件");

        if (!$execute) {
            return self::SUCCESS;
        }

        $svc = app(EngineerMailScoringService::class);
        $scanned = 0; $priceFound = 0; $tooLow = 0; $changed = 0; $err = 0;
        $base->with('email')->orderBy('id')->chunkById(300, function ($rows) use ($svc, $limit, &$scanned, &$priceFound, &$tooLow, &$changed, &$err) {
            foreach ($rows as $ems) {
                if ($limit > 0 && $scanned >= $limit) return false;
                $scanned++;
                if (!$ems->email) continue;
                $oldStatus = $ems->status;
                try {
                    $new = $svc->score($ems->email, false);
                    $reasons = (array) ($new->score_reasons ?? []);
                    if (!in_array('no_unit_price', $reasons, true)) $priceFound++;
                    if (in_array('unit_price_too_low', $reasons, true)) $tooLow++;
                    if ($new->status !== $oldStatus) $changed++;
                } catch (\Throwable $e) {
                    $err++;
                    Log::warning("[rescore-engineer-no-price] id={$ems->id} " . $e->getMessage());
                }
            }
            $this->info("  scanned={$scanned} 単価復活={$priceFound}（うち35万未満除外={$tooLow}） status変化={$changed} err={$err}");
            gc_collect_cycles();
            return true;
        });

        $this->info("完了: scanned={$scanned} 単価復活={$priceFound}（うち35万未満除外={$tooLow}） status変化={$changed} err={$err}");
        Log::info('rescore-engineer-no-price', compact('scanned', 'priceFound', 'tooLow', 'changed', 'err'));
        return self::SUCCESS;
    }
}
