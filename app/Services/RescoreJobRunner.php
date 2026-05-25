<?php

namespace App\Services;

use App\Models\RescoreJob;
use Illuminate\Support\Facades\Log;

/**
 * rescore_jobs を 1 件ずつ進める Schedule tick の本体 (docs #4)。
 *
 * 毎分の Schedule::call('rescore-jobs-tick') から呼ばれ、最古の未完了 job を
 * 時間ボックス内で複数バッチ処理する。Schedule は Auth 無し(CLI)で動くため、
 * 対象テナントは job->tenant_id を rescoreAll に明示的に渡してスコープする。
 *
 * 進捗は固定 batch 前進 + total_count 到達で完了判定 (rescoreAll の戻り値には依存しない)。
 * rescore はレコードを削除しないため offset ベースのページングが安定する。
 */
class RescoreJobRunner
{
    /** 1 バッチの件数 */
    private const BATCH_SIZE = 300;

    /** 1 tick の処理時間バジェット(秒)。Schedule の everyMinute に収める */
    private const TIME_BUDGET_SEC = 50;

    public function __construct(
        private ProjectMailScoringService  $projectScoring,
        private EngineerMailScoringService $engineerScoring,
    ) {}

    public function tick(): void
    {
        // Auth 無し → GlobalScope 無効 → 全テナント横断で最古の未完了 job を取得
        $job = RescoreJob::whereIn('status', RescoreJob::ACTIVE_STATUSES)
            ->orderBy('id')
            ->first();

        if (!$job) {
            return;
        }

        if ($job->status === RescoreJob::STATUS_PENDING) {
            $job->status     = RescoreJob::STATUS_PROCESSING;
            $job->started_at = now();
            $job->save();
        }

        $deadline = microtime(true) + self::TIME_BUDGET_SEC;

        try {
            while (microtime(true) < $deadline && $job->cursor_offset < $job->total_count) {
                $this->runBatch($job);

                $job->cursor_offset  += self::BATCH_SIZE;
                $job->processed_count = min($job->cursor_offset, $job->total_count);
                $job->save();
            }

            if ($job->cursor_offset >= $job->total_count) {
                $job->status      = RescoreJob::STATUS_COMPLETED;
                $job->finished_at = now();
                $job->save();
                Log::info('[RescoreJobRunner] 完了', [
                    'job_id' => $job->id,
                    'type'   => $job->type,
                    'total'  => $job->total_count,
                ]);
            }
        } catch (\Throwable $e) {
            $job->status        = RescoreJob::STATUS_FAILED;
            $job->error_message = mb_substr($e->getMessage(), 0, 1000);
            $job->finished_at   = now();
            $job->save();
            Log::error('[RescoreJobRunner] 失敗', [
                'job_id' => $job->id,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private function runBatch(RescoreJob $job): void
    {
        if ($job->type === RescoreJob::TYPE_PROJECT) {
            $this->projectScoring->rescoreAll(self::BATCH_SIZE, $job->cursor_offset, $job->tenant_id);
        } else {
            $this->engineerScoring->rescoreAll(self::BATCH_SIZE, $job->cursor_offset, $job->tenant_id);
        }
    }
}
