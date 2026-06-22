<?php

namespace App\Console\Commands;

use App\Models\EngineerMailSource;
use App\Services\EngineerMailScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 技術者メールの抽出情報（氏名・年齢・所属・稼働開始日・最寄駅・スキル）を安全に再抽出して更新する。
 *
 * reextract-safe（案件側）の技術者版。非破壊:
 *  - 本文か HTML 本文が残る行のみ対象。purge 済み（両方空）はスキップ。
 *  - 新しい抽出値が null の項目は上書きしない（既存の良いデータを潰さない）。
 *  - score / status / 単価は変更しない（単価は除外・取込み判定と連動するため scoring に委ねる）。
 *
 * 用途: 稼働開始日ラベルの是正（【稼働開始日】即日 → "日】即日" 崩れ。2026-06-22）等の
 *       抽出ロジック改善を既存データへ反映する。
 *
 * 例:
 *   php artisan engineer-mails:reextract-safe                 # DRY-RUN
 *   php artisan engineer-mails:reextract-safe --execute
 *   php artisan engineer-mails:reextract-safe --execute --limit=1000
 */
class ReextractEngineerMailSafe extends Command
{
    protected $signature = 'engineer-mails:reextract-safe
        {--execute : 実際に更新する（未指定は DRY-RUN）}
        {--limit=0 : 処理上限（0=無制限）}';

    protected $description = '技術者メールの抽出情報を本文あり行のみ・非null上書きで安全に再抽出する（score/status/単価 不変）';

    // 再抽出で更新する項目。単価(unit_price_*)は除外・取込判定と連動するため触らない。
    private const FIELDS = [
        'name', 'age', 'affiliation_type', 'affiliation',
        'available_from', 'nearest_station', 'skills',
    ];

    public function handle(EngineerMailScoringService $scoring): int
    {
        $execute = (bool) $this->option('execute');
        $limit   = (int) $this->option('limit');

        $this->info($execute ? 'モード: 再抽出して更新' : 'モード: DRY-RUN（更新しません）');

        $query = EngineerMailSource::with('email')
            ->whereHas('email', fn ($e) => $e->where(function ($q) {
                $q->where(fn ($q2) => $q2->whereNotNull('body_text')->where('body_text', '<>', ''))
                  ->orWhere(fn ($q2) => $q2->whereNotNull('body_html')->where('body_html', '<>', ''));
            }))
            ->orderBy('id');

        $scanned = 0;
        $changed = 0;

        $query->chunkById(500, function ($rows) use ($scoring, $execute, $limit, &$scanned, &$changed) {
            foreach ($rows as $ems) {
                if ($limit > 0 && $scanned >= $limit) return false;
                $scanned++;
                if (!$ems->email) continue;

                $extracted = $scoring->extractFieldsWithoutAttachment($ems->email);

                $dirty = [];
                foreach (self::FIELDS as $f) {
                    $new = $extracted[$f] ?? null;
                    if ($new === null) continue;
                    if ($ems->{$f} != $new) $dirty[$f] = $new;
                }
                if (empty($dirty)) continue;

                $changed++;
                if ($execute) {
                    EngineerMailSource::withoutGlobalScopes()->whereKey($ems->id)->update($dirty);
                }
                if ($changed <= 15) {
                    $this->line("  #{$ems->id}: " . collect($dirty)->map(fn ($v, $k) => "{$k}=" . (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v))->implode(' '));
                }
            }
            $this->info("  ... scanned={$scanned} changed={$changed}");
            return true;
        });

        $this->info("完了: scanned={$scanned} / " . ($execute ? "changed={$changed}" : "would-change={$changed}"));
        Log::info('engineer-mails:reextract-safe', compact('execute', 'scanned', 'changed'));

        return self::SUCCESS;
    }
}
