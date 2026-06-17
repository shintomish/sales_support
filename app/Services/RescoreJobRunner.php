<?php

namespace App\Services;

use App\Models\Email;
use App\Models\RescoreJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /**
     * mark_read 用バッチ件数。emails は is_read を含む index が複数あり
     * UPDATE が非 HOT 化、加えて body_text の trgm GIN (368MB) への
     * 新タプル挿入コストが乗るため小さめに保つ。[[project_markallread_sync_timeout]]
     */
    private const MARK_READ_BATCH_SIZE = 200;

    /** mark_read 1 バッチに許容する statement_timeout (既定 2min では足りないため) */
    private const MARK_READ_STATEMENT_TIMEOUT = '5min';

    /** mark_read tick の 1 回あたり最大ループ回数 (取込スケジューラと並行しても暴走しない安全弁) */
    private const MARK_READ_MAX_BATCHES_PER_TICK = 50;

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
            if ($job->type === RescoreJob::TYPE_MARK_READ) {
                $this->runMarkReadTick($job, $deadline);
                return;
            }

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

    /**
     * mark_read 用の tick 処理。
     *
     * 完了判定は cursor_offset/total_count ではなく「未読が残っているか」で行う。
     * 取込スケジューラが裏で is_read=false を増やしても、本 tick の時間バジェット内で
     * 取り切れるだけ取り続け、次 tick で再開する。
     */
    private function runMarkReadTick(RescoreJob $job, float $deadline): void
    {
        $batches = 0;
        while (microtime(true) < $deadline && $batches < self::MARK_READ_MAX_BATCHES_PER_TICK) {
            $batches++;
            $hasMore = $this->runMarkReadBatch($job);
            if (!$hasMore) {
                $job->status      = RescoreJob::STATUS_COMPLETED;
                $job->finished_at = now();
                $job->save();
                Cache::forget("emails:unread_count:tenant:{$job->tenant_id}");
                // Kagoya 取込が「直近 markAllRead 後の取込遅延メール」を既読化する判定で
                // 参照するキャッシュも合わせて invalidate (KagoyaMailService::shouldImportAsRead)
                Cache::forget("kagoya:last_mark_all_read:{$job->tenant_id}");
                Log::info('[RescoreJobRunner] mark_read 完了', [
                    'job_id'    => $job->id,
                    'tenant_id' => $job->tenant_id,
                    'processed' => $job->processed_count,
                    'total'     => $job->total_count,
                ]);
                return;
            }
        }
    }

    /**
     * mark_read 1 バッチ。未読 id を MARK_READ_BATCH_SIZE 件取得→whereIn UPDATE。
     * 戻り値: 処理対象が見つかったか (false なら未読 0 件)
     *
     * emails の非HOT UPDATE が既定 statement_timeout (2min) を超えるため
     * 各バッチ専用に SET LOCAL で 5min まで引き上げる。
     */
    private function runMarkReadBatch(RescoreJob $job): bool
    {
        return DB::transaction(function () use ($job) {
            // SET LOCAL は Postgres 固有。sqlite 等(テスト)では no-op にする（本番 pgsql は従来どおり）。
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL statement_timeout = '" . self::MARK_READ_STATEMENT_TIMEOUT . "'");
            }

            $ids = Email::withoutGlobalScopes()
                ->where('tenant_id', $job->tenant_id)
                ->where('is_read', false)
                ->limit(self::MARK_READ_BATCH_SIZE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return false;
            }

            $updated = Email::withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->update(['is_read' => true]);

            $job->processed_count = ($job->processed_count ?? 0) + $updated;
            $job->cursor_offset   = $job->processed_count;
            $job->save();

            return true;
        });
    }
}
