<?php

namespace App\Console\Commands;

use App\Models\EngineerMailSource;
use App\Models\ProjectMailSource;
use App\Services\EngineerMailScoringService;
use App\Services\ProjectMailScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 非開発ロール（ヘルプデスク/運用保守/キッティング等・ROLE_SKILLS）を既存行の検索用 skills に反映する。
 *
 * extractSkills に ROLE_SKILLS を追加した変更（2026-07-10）を既存データへ反映する一度きりの後処理。
 * スコア/ステータスは一切変更せず、**skills 列だけ**を再抽出して更新する（中立性維持）。対象は該当
 * ロール語を件名/本文に含む行に絞る。extract() 全体を呼ぶが結果は skills のみ採用（他フィールド不変）。
 *
 *   php artisan mail-sources:reextract-role-skills                 # DRY-RUN（対象件数のみ）
 *   php artisan mail-sources:reextract-role-skills --execute
 *   php artisan mail-sources:reextract-role-skills --type=engineer --execute
 */
class ReextractRoleSkills extends Command
{
    protected $signature = 'mail-sources:reextract-role-skills {--execute} {--type=both : project|engineer|both} {--limit=0}';
    protected $description = '非開発ロール(ROLE_SKILLS)を既存行の検索用skillsに反映（skills列のみ更新・スコア不変）';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $type    = (string) $this->option('type');
        $limit   = (int) $this->option('limit');
        $this->info($execute ? 'モード: 更新' : 'モード: DRY-RUN（対象件数のみ・更新なし）');

        // 両サービス同一リスト。POSIX 正規表現の alternation（ロール語に正規表現メタ文字なし）
        $re = implode('|', ProjectMailScoringService::ROLE_SKILLS);

        if (in_array($type, ['both', 'project'], true)) {
            $this->reextractSet(
                'PMS',
                ProjectMailSource::query(),
                $re,
                fn ($email) => app(ProjectMailScoringService::class)->extract($email)['required_skills'] ?? [],
                'required_skills',
                $execute,
                $limit,
            );
        }
        if (in_array($type, ['both', 'engineer'], true)) {
            $this->reextractSet(
                'EMS',
                EngineerMailSource::query(),
                $re,
                fn ($email) => app(EngineerMailScoringService::class)->extract($email, false)['skills'] ?? [],
                'skills',
                $execute,
                $limit,
            );
        }
        return self::SUCCESS;
    }

    private function reextractSet(string $label, $query, string $re, callable $extractSkills, string $col, bool $execute, int $limit): void
    {
        $base = $query->whereNotNull('email_id')
            ->whereHas('email', fn ($e) => $e
                ->where('subject', '~*', $re)
                ->orWhere('body_text', '~*', $re)
                ->orWhere('body_html', '~*', $re));

        $target = (clone $base)->count();
        $this->info("[{$label}] 対象（ロール語を件名/本文に含む）: {$target}件");
        if (!$execute) return;

        $scanned = 0; $changed = 0; $err = 0;
        $base->with('email')->orderBy('id')->chunkById(300, function ($rows) use ($extractSkills, $col, $limit, &$scanned, &$changed, &$err, $label) {
            foreach ($rows as $row) {
                if ($limit > 0 && $scanned >= $limit) return false;
                $scanned++;
                if (!$row->email) continue;
                try {
                    $new = array_values($extractSkills($row->email));
                    $old = (array) ($row->{$col} ?? []);
                    // 順序無視で差分判定
                    $a = $new; $b = $old; sort($a); sort($b);
                    if ($a !== $b) {
                        $row->update([$col => $new ?: null]);
                        $changed++;
                    }
                } catch (\Throwable $e) {
                    $err++;
                    Log::warning("[reextract-role-skills][{$label}] id={$row->id} " . $e->getMessage());
                }
            }
            $this->info("  scanned={$scanned} changed={$changed} err={$err}");
            gc_collect_cycles();
            return true;
        });

        $this->info("[{$label}] 完了: scanned={$scanned} changed={$changed} err={$err}");
        Log::info('reextract-role-skills', compact('label', 'scanned', 'changed', 'err'));
    }
}
