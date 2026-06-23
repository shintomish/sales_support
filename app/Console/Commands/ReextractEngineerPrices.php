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
 *  - **単価(unit_price_min/max)のみ更新**。score() は呼ばないため score 閾値(40)による可視性変更
 *    (review→excluded) を起こさない。例外は業務ルール「35万未満は除外」のみ適用する。
 *    （2026-06-23: score() 再評価で score<40 の有価格技術者を 525 件除外してしまった事故を受け修正）
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

    protected $description = '単価が空の技術者メールを再抽出して unit_price のみ埋める（円建て/税抜等の後追い・status非変更/35万未満のみ除外）';

    private const PRICE_MIN_FLOOR = 35; // 万円：これ未満は除外（EngineerMailScoringService と同値）

    public function handle(EngineerMailScoringService $scoring): int
    {
        $execute = (bool) $this->option('execute');
        $limit   = (int) $this->option('limit');

        $this->info($execute ? 'モード: 単価のみ補完して更新' : 'モード: DRY-RUN（更新しません）');

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

                        // 本文/HTML からの抽出のみ（score()は呼ばない＝score閾値による可視性変更を起こさない）
                        $d   = $scoring->extractFieldsWithoutAttachment($email);
                        $min = $d['unit_price_min'] ?? null;
                        $max = $d['unit_price_max'] ?? null;
                        $p   = $min ?? $max;
                        if ($p === null) { $stillNull++; continue; }

                        // status は据え置き。例外は業務ルールの「35万未満は除外」のみ適用。
                        $tooLow = (int) $p < self::PRICE_MIN_FLOOR;
                        if ($tooLow) $excluded++; else $priced++;

                        if ($execute) {
                            $update = ['unit_price_min' => $min, 'unit_price_max' => $max];
                            if ($tooLow) {
                                $reasons = (array) ($ems->score_reasons ?? []);
                                if (!in_array('unit_price_too_low', $reasons, true)) $reasons[] = 'unit_price_too_low';
                                if (!in_array('excluded', $reasons, true))           $reasons[] = 'excluded';
                                $update['status']        = 'excluded';
                                $update['score_reasons'] = $reasons;
                            }
                            EngineerMailSource::withoutGlobalScopes()->whereKey($ems->id)->update($update);
                        }
                    } catch (\Throwable $e) {
                        $err++;
                        Log::warning('[reextract-prices] ems=' . $ems->id . ' ' . $e->getMessage());
                    }
                }
                $this->info("  ... scanned={$scanned} priced={$priced} excluded(<35万)={$excluded} stillNull={$stillNull} err={$err}");
                gc_collect_cycles();
                return true;
            });

        $this->info("完了: scanned={$scanned} / 単価入った={$priced} / 除外化={$excluded} / 依然null={$stillNull} / 例外={$err}（" . ($execute ? 'execute' : 'dry-run') . '）');
        Log::info('engineer-mails:reextract-prices', compact('execute', 'scanned', 'priced', 'excluded', 'stillNull', 'err'));

        return self::SUCCESS;
    }
}
