<?php

namespace App\Jobs;

use App\Models\GmailToken;
use App\Services\GmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * タイムアウト（秒）
     * Gmail API の呼び出しが多い場合を考慮して長めに設定
     */
    public int $timeout = 120;

    /**
     * 失敗時のリトライ回数
     */
    public int $tries = 2;

    /**
     * 特定テナントのみ同期する場合に指定（null = 全テナント）
     */
    public function __construct(
        private readonly ?int $tenantId = null
    ) {}

    public function handle(GmailService $gmailService): void
    {
        // 対象トークンを取得（tenantId指定があればそのテナントのみ）
        $query = GmailToken::query();
        if ($this->tenantId !== null) {
            $query->where('tenant_id', $this->tenantId);
        }

        $tokens = $query->get();

        if ($tokens->isEmpty()) {
            Log::info('[SyncEmailsJob] Gmail連携済みトークンなし。スキップ。');
            return;
        }

        $totalStored = 0;
        $errors      = 0;

        foreach ($tokens as $token) {
            try {
                $stored = $gmailService->fetchAndStoreEmails($token, maxResults: 30);
                $totalStored += $stored;

                Log::info('[SyncEmailsJob] 同期完了', [
                    'tenant_id'    => $token->tenant_id,
                    'gmail'        => $token->gmail_address,
                    'stored_count' => $stored,
                ]);
            } catch (\Throwable $e) {
                $errors++;
                Log::error('[SyncEmailsJob] 同期エラー', [
                    'tenant_id' => $token->tenant_id,
                    'gmail'     => $token->gmail_address,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        Log::info('[SyncEmailsJob] 全テナント同期完了', [
            'total_stored' => $totalStored,
            'errors'       => $errors,
            'tokens'       => $tokens->count(),
        ]);
    }
}
