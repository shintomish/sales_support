<?php

namespace App\Console\Commands;

use App\Models\Email;
use App\Models\GmailToken;
use App\Services\GmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 分類済みメール（classified_at が設定済み）を Gmail のゴミ箱に移動するコマンド。
 * gmail_trashed_at が未設定のものだけを対象にし、重複実行を防ぐ。
 */
class TrashClassifiedEmails extends Command
{
    protected $signature = 'gmail:trash-classified {--dry-run : 実際の変更を行わずに対象件数を確認}';
    protected $description = '分類済みメールを Gmail のゴミ箱に移動する';

    public function __construct(private readonly GmailService $gmailService)
    {
        parent::__construct();
    }

    /** Eloquent モデル累積によるメモリ枯渇防止のため、明示的に上限を引き上げる */
    private const MEMORY_LIMIT = '768M';
    /** バッチ処理中に到達した時点で安全停止するメモリ閾値（バイト） */
    private const MEMORY_GUARD_BYTES = 600 * 1024 * 1024;
    /** Gmail API batchModify は最大1000件可だが、メモリ安全のため500に分割 */
    private const CHUNK_SIZE = 500;

    public function handle(): int
    {
        @ini_set('memory_limit', self::MEMORY_LIMIT);

        $dryRun = $this->option('dry-run');

        // テナントごとの GmailToken を全取得
        $tokens = GmailToken::all();

        if ($tokens->isEmpty()) {
            $this->info('GmailToken が登録されていません。');
            return 0;
        }

        $totalTrashed = 0;
        $totalFailed  = 0;

        foreach ($tokens as $token) {
            [$trashed, $failed] = $this->processToken($token, $dryRun);
            $totalTrashed += $trashed;
            $totalFailed  += $failed;
        }

        $label = $dryRun ? '[DRY-RUN] ' : '';
        $peakMb = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
        $this->info("{$label}完了: ゴミ箱移動={$totalTrashed}件, 失敗={$totalFailed}件 / peak={$peakMb}MB");

        if ($totalTrashed > 0 || $totalFailed > 0) {
            Log::info("[TrashClassifiedEmails] {$label}ゴミ箱移動={$totalTrashed}件, 失敗={$totalFailed}件, peak={$peakMb}MB");
        }

        return 0;
    }

    private function processToken(GmailToken $token, bool $dryRun): array
    {
        // 分類済み かつ まだゴミ箱に移動していないメールを件数取得
        // KAGOYA IMAP 由来のメール (gmail_message_id が "imap-..." プレフィックス) は
        // Gmail に存在しないため除外する
        $baseQuery = Email::where('tenant_id', $token->tenant_id)
            ->whereNotNull('classified_at')
            ->whereNull('gmail_trashed_at')
            ->whereNotNull('gmail_message_id')
            ->where('gmail_message_id', 'NOT LIKE', 'imap-%');

        $total = $baseQuery->count();

        if ($total === 0) {
            return [0, 0];
        }

        $this->line("テナント={$token->tenant_id} ({$token->gmail_address}): 対象={$total}件");

        if ($dryRun) {
            return [$total, 0];
        }

        $trashed = 0;
        $failed  = 0;
        $aborted = false;

        // chunkById は ID で前進するため、内部でレコード更新しても安全。
        // CHUNK_SIZE=500 はメモリ消費とAPI呼び出し数のバランス。
        $baseQuery
            ->select(['id', 'gmail_message_id'])
            ->chunkById(self::CHUNK_SIZE, function ($emails) use ($token, &$trashed, &$failed, &$aborted) {
                // メモリ閾値到達で安全停止（次回実行で残りを処理）
                if (memory_get_usage(true) > self::MEMORY_GUARD_BYTES) {
                    Log::warning('[TrashClassifiedEmails] メモリ閾値到達で中断', [
                        'tenant_id' => $token->tenant_id,
                        'used_mb'   => round(memory_get_usage(true) / 1024 / 1024, 1),
                    ]);
                    $aborted = true;
                    return false; // chunkById ループ終了
                }

                $messageIds = $emails->pluck('gmail_message_id')->all();
                $emailIds   = $emails->pluck('id')->all();

                $success = $this->gmailService->batchTrashEmails($token, $messageIds);

                if ($success) {
                    Email::whereIn('id', $emailIds)->update(['gmail_trashed_at' => now()]);
                    $trashed += count($messageIds);
                } else {
                    $failed += count($messageIds);
                }

                // チャンク間で循環参照を解放（長時間実行時のリーク対策）
                unset($messageIds, $emailIds, $emails);
                gc_collect_cycles();
            });

        if ($aborted) {
            $this->warn("テナント={$token->tenant_id}: メモリ閾値で中断（次回実行で続行）");
        }

        return [$trashed, $failed];
    }
}
