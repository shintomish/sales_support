<?php

namespace App\Console\Commands;

use App\Models\EngineerMailSource;
use App\Services\EngineerMailScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 単価が空（unit_price_min/max とも null）の技術者メールを再抽出し、unit_price を後追いで埋める。
 *
 * 背景: 単価抽出の対応フォーマットを増やした（例: "単金（税抜）：70万"、円建て "単金額：700,000〜750,000円"）
 *       後に、既存の取りこぼし行へ反映するための後追いコマンド。reextract-safe は単価を触らない設計のため別立て。
 *
 * 安全性:
 *  - 対象は unit_price が null かつ status<>'excluded' の行のみ（既に値がある行は触らない）。
 *  - score() を通すため isExcluded（自社/受信除外ドメイン）・35万未満除外などの本番ロジックと完全整合。
 *  - chunkById でメモリ一定。tinker(psysh) を使わないので ProcessForker OOM を起こさない。
 *
 * 例:
 *   php artisan engineer-mails:reextract-prices                 # DRY-RUN（件数のみ）
 *   php artisan engineer-mails:reextract-prices --execute
 *   php artisan engineer-mails:reextract-prices --execute --limit=500
 */
class ReextractEngineerPrices extends Command
{
    protected $signature = 'engineer-mails:reextract-prices
        {--execute : 実際に再スコア/更新する（未指定は DRY-RUN）}
        {--limit=0 : 処理上限（0=無制限）}';

    protected $description = '単価が空の技術者メールを再抽出して unit_price を埋める（円建て/税抜等の新フォーマット後追い・score/status整合）';

    public function handle(EngineerMailScoringService $scoring): int
    {
        $execute = (bool) $this->option('execute');
        $limit   = (int) $this->option('limit');

        $this->info($execute ? 'モード: 再抽出して更新' : 'モード: DRY-RUN（更新しません）');

        $scanned = 0; $priced = 0; $excluded = 0; $stillNull = 0; $err = 0;

        EngineerMailSource::withoutGlobalScopes()
            ->whereNull('unit_price_min')->whereNull('unit_price_max')
            ->where('status', '<>', 'excluded')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($scoring, $execute, $limit, &$scanned, &$priced, &$excluded, &$stillNull, &$err) {
                foreach ($rows as $ems) {
                    if ($limit > 0 && $scanned >= $limit) return false;
                    $scanned++;
                    try {
                        $email = $ems->email; // lazy load（chunk内で1件ずつ・メモリ一定）
                        if (!$email) { $stillNull++; continue; }

                        if ($execute) {
                            $res = $scoring->score($email)->fresh();
                            $p = $res->unit_price_min ?? $res->unit_price_max;
                            if ($res->status === 'excluded') $excluded++;
                            elseif ($p !== null) $priced++;
                            else $stillNull++;
                        } else {
                            $d = $scoring->extractFieldsWithoutAttachment($email);
                            $p = $d['unit_price_min'] ?? $d['unit_price_max'] ?? null;
                            if ($p !== null) $priced++; else $stillNull++;
                        }
                    } catch (\Throwable $e) {
                        $err++;
                        Log::warning('[reextract-prices] ems=' . $ems->id . ' ' . $e->getMessage());
                    }
                }
                $this->info("  ... scanned={$scanned} priced={$priced} excluded={$excluded} stillNull={$stillNull} err={$err}");
                gc_collect_cycles();
                return true;
            });

        $this->info("完了: scanned={$scanned} / 単価入った={$priced} / 除外化={$excluded} / 依然null={$stillNull} / 例外={$err}（" . ($execute ? 'execute' : 'dry-run') . '）');
        Log::info('engineer-mails:reextract-prices', compact('execute', 'scanned', 'priced', 'excluded', 'stillNull', 'err'));

        return self::SUCCESS;
    }
}
