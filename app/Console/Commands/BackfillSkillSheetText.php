<?php

namespace App\Console\Commands;

use App\Models\EngineerMailSource;
use App\Services\SkillSheetTextExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 既存 EMS のうち parsed_skill_sheet_text が空のものについて、
 * 添付スキルシートから本文抽出してバックフィル (docs/480 Phase 4)。
 *
 * 使い方:
 *   php artisan engineer-mail-sources:backfill-skillsheet --limit=100
 *   php artisan engineer-mail-sources:backfill-skillsheet --tenant=1 --dry-run
 */
class BackfillSkillSheetText extends Command
{
    protected $signature = 'engineer-mail-sources:backfill-skillsheet
        {--tenant= : テナントを 1 つに絞る (省略時は全テナント)}
        {--limit=50 : 1 回の実行で処理する EMS 件数}
        {--dry-run : 実行せず対象件数だけ報告}';

    protected $description = '既存 engineer_mail_sources の parsed_skill_sheet_text をバックフィル';

    public function handle(SkillSheetTextExtractor $extractor): int
    {
        $limit  = (int) $this->option('limit');
        $tenant = $this->option('tenant');
        $dryRun = (bool) $this->option('dry-run');

        $query = EngineerMailSource::query()
            ->whereNull('parsed_skill_sheet_text')
            ->whereHas('email.attachments')
            ->with('email.attachments')
            ->orderByDesc('id');

        if ($tenant) $query->where('tenant_id', (int) $tenant);

        $totalEligible = $query->count();
        $this->info("対象 EMS (parsed_skill_sheet_text=null かつ添付あり): {$totalEligible}件");
        if ($dryRun) {
            $this->line('[dry-run] 実行はスキップ');
            return self::SUCCESS;
        }

        $targets = $query->limit($limit)->get();
        $this->info("今回処理: {$targets->count()}件 (limit={$limit})");

        $ok = 0; $skip = 0; $err = 0;
        $bar = $this->output->createProgressBar($targets->count());
        foreach ($targets as $ems) {
            try {
                $text = $extractor->extractFromAttachments($ems->email->attachments);
                if ($text) {
                    $ems->update(['parsed_skill_sheet_text' => $text]);
                    $ok++;
                } else {
                    $skip++;
                }
            } catch (\Throwable $e) {
                $err++;
                Log::warning('[BackfillSkillSheet] failed', ['ems_id' => $ems->id, 'error' => $e->getMessage()]);
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info("結果: 成功 {$ok}件 / 抽出不可 {$skip}件 / エラー {$err}件");
        $this->line("残り対象: " . max(0, $totalEligible - $targets->count()) . '件');

        return self::SUCCESS;
    }
}
