<?php

namespace App\Console\Commands;

use App\Models\Email;
use App\Models\EngineerMailSource;
use App\Models\ProjectMailSource;
use App\Services\EmailClassificationService;
use App\Services\EngineerMailScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 誤分類された案件メール（本来は技術者メール）を、現在の分類ルールで再判定して
 * 技術者側へ安全に移し替える。手動移動 (moveToEngineer) と同じ処理を流用する。
 *
 * 用途: 「注力個人」等のキーワード追加（2026-06-02）より前に body_url 理由で
 *       project に分類されたまま取り残された行を是正する。
 *
 * 安全策（非破壊・冪等）:
 *  - registered_at ありはスキップ（請求等に紐付いた行を触らない）。
 *  - 技術者→案件 へ手動移動された行（trashed engineer_mail_source あり）はスキップし、
 *    ユーザーの手動判断を上書きしない。
 *  - 現ルールが engineer を返す行のみ対象（project のままなら何もしない）。
 *  - 1 件ずつ transaction（moveToEngineer と同一: category 切替→ems restore→score→pms delete）。
 *
 * 例:
 *   php artisan mails:reclassify-safe                 # DRY-RUN
 *   php artisan mails:reclassify-safe --execute
 *   php artisan mails:reclassify-safe --execute --limit=100
 */
class ReclassifyMisclassifiedSafe extends Command
{
    protected $signature = 'mails:reclassify-safe
        {--execute : 実際に移動する（未指定は DRY-RUN）}
        {--limit=0 : 処理上限（0=無制限）}';

    protected $description = '誤分類の案件→技術者メールを現行ルールで再判定して安全に移し替える（手動移動・登録済みは保護）';

    public function handle(
        EmailClassificationService $classifier,
        EngineerMailScoringService $engineerScoring
    ): int {
        $execute = (bool) $this->option('execute');
        $limit   = (int) $this->option('limit');

        $this->info($execute ? 'モード: 技術者へ移動' : 'モード: DRY-RUN（移動しません）');

        $scanned = 0;
        $moved   = 0;
        $skippedManual = 0;

        Email::where('category', 'project')
            ->whereNull('registered_at')
            ->with('attachments')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (
                $classifier, $engineerScoring, $execute, $limit,
                &$scanned, &$moved, &$skippedManual
            ) {
                foreach ($rows as $email) {
                    if ($limit > 0 && $scanned >= $limit) return false;
                    $scanned++;

                    [$category] = $classifier->predictCategory($email);
                    if ($category !== 'engineer') continue;

                    // 技術者→案件へ手動移動された行は保護（ユーザー判断を尊重）
                    if (EngineerMailSource::onlyTrashed()->where('email_id', $email->id)->exists()) {
                        $skippedManual++;
                        continue;
                    }

                    $moved++;
                    if ($moved <= 20) {
                        $this->line("  email#{$email->id}: project→engineer | " . mb_substr($email->subject ?? '', 0, 50));
                    }

                    if ($execute) {
                        DB::transaction(function () use ($email, $engineerScoring) {
                            $email->update(['category' => 'engineer']);
                            EngineerMailSource::withTrashed()->where('email_id', $email->id)->restore();
                            $engineerScoring->score($email); // updateOrCreate(email_id) で冪等
                            ProjectMailSource::where('email_id', $email->id)->delete(); // soft delete
                        });
                    }
                }
                $this->info("  ... scanned={$scanned} moved={$moved} 手動保護={$skippedManual}");
                return true;
            });

        $this->info("完了: scanned={$scanned} / " . ($execute ? "moved={$moved}" : "would-move={$moved}") . " / 手動保護={$skippedManual}");
        Log::info('mails:reclassify-safe', compact('execute', 'scanned', 'moved', 'skippedManual'));

        return self::SUCCESS;
    }
}
