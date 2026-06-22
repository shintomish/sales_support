<?php

namespace App\Console\Commands;

use App\Models\ProjectMailSource;
use App\Services\ProjectMailScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 案件メールの抽出情報（単価・営業担当・顧客名・スキル等）を安全に再抽出して更新する。
 *
 * reextract-all との違い（非破壊）:
 *  - 本文が残っている行のみ対象（body_text か body_html のどちらかが残る行）。purge 済み（両方 NULL/空）はスキップ。
 *  - 新しい抽出値が null の項目は上書きしない（既存の良いデータを null で潰さない）。
 * score / status は変更しない（運用中の順位・状態を動かさない）。
 *
 * 用途: 単価抽出のラベル近傍化（2026-06-22）等の抽出ロジック改善を既存データへ反映する。
 *
 * 例:
 *   php artisan project-mails:reextract-safe                 # DRY-RUN
 *   php artisan project-mails:reextract-safe --execute
 *   php artisan project-mails:reextract-safe --execute --limit=1000
 */
class ReextractProjectMailSafe extends Command
{
    protected $signature = 'project-mails:reextract-safe
        {--execute : 実際に更新する（未指定は DRY-RUN）}
        {--limit=0 : 処理上限（0=無制限）}';

    protected $description = '案件メールの抽出情報を本文あり行のみ・非null上書きで安全に再抽出する（score/status 不変）';

    // 再抽出で更新する抽出項目（score/status/received_at 等のメタは触らない）
    private const FIELDS = [
        'customer_name', 'sales_contact', 'phone', 'title',
        'required_skills', 'process', 'work_location', 'remote_ok',
        'unit_price_min', 'unit_price_max', 'start_date', 'contract_type',
        'age_limit', 'nationality_ok', 'supply_chain',
    ];

    public function handle(ProjectMailScoringService $scoring): int
    {
        $execute = (bool) $this->option('execute');
        $limit   = (int) $this->option('limit');

        $this->info($execute ? 'モード: 再抽出して更新' : 'モード: DRY-RUN（更新しません）');

        // 本文 (body_text) か HTML 本文 (body_html) のどちらかが残る行のみ。
        // HTML 専用メール（body_text='' で取り込まれる整形メール）も営業担当・電話を
        // 再抽出できるよう対象に含める。両方空（purge 済み）はスキップ。
        $query = ProjectMailSource::with('email')
            ->whereHas('email', fn ($e) => $e->where(function ($q) {
                $q->where(fn ($q2) => $q2->whereNotNull('body_text')->where('body_text', '<>', ''))
                  ->orWhere(fn ($q2) => $q2->whereNotNull('body_html')->where('body_html', '<>', ''));
            }))
            ->orderBy('id');

        $scanned = 0;
        $changed = 0;

        $query->chunkById(500, function ($rows) use ($scoring, $execute, $limit, &$scanned, &$changed) {
            foreach ($rows as $pms) {
                if ($limit > 0 && $scanned >= $limit) return false;
                $scanned++;
                if (!$pms->email) continue;

                $extracted = $scoring->extract($pms->email);

                // 新値が null の項目は除外（非破壊マージ）し、実際に値が変わる項目だけ拾う
                $dirty = [];
                foreach (self::FIELDS as $f) {
                    $new = $extracted[$f] ?? null;
                    if ($new === null) continue;
                    if ($pms->{$f} != $new) $dirty[$f] = $new;
                }
                if (empty($dirty)) continue;

                $changed++;
                if ($execute) {
                    ProjectMailSource::withoutGlobalScopes()->whereKey($pms->id)->update($dirty);
                }
                if ($changed <= 15) {
                    $this->line("  #{$pms->id}: " . collect($dirty)->map(fn ($v, $k) => "{$k}=" . (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v))->implode(' '));
                }
            }
            $this->info("  ... scanned={$scanned} changed={$changed}");
            return true;
        });

        $this->info("完了: scanned={$scanned} / " . ($execute ? "changed={$changed}" : "would-change={$changed}"));
        Log::info('project-mails:reextract-safe', compact('execute', 'scanned', 'changed'));

        return self::SUCCESS;
    }
}
