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

    public function handle(): int
    {
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
        $this->info("{$label}完了: ゴミ箱移動={$totalTrashed}件, 失敗={$totalFailed}件");

        if ($totalTrashed > 0) {
            Log::info("[TrashClassifiedEmails] {$label}ゴミ箱移動={$totalTrashed}件, 失敗={$totalFailed}件");
        }

        return 0;
    }

    private function processToken(GmailToken $token, bool $dryRun): array
    {
        // 分類済み かつ まだゴミ箱に移動していないメールを取得
        $emails = Email::where('tenant_id', $token->tenant_id)
            ->whereNotNull('classified_at')
            ->whereNull('gmail_trashed_at')
            ->whereNotNull('gmail_message_id')
            ->get();

        if ($emails->isEmpty()) {
            return [0, 0];
        }

        $this->line("テナント={$token->tenant_id} ({$token->gmail_address}): 対象={$emails->count()}件");

        if ($dryRun) {
            return [$emails->count(), 0];
        }

        $trashed = 0;
        $failed  = 0;

        foreach ($emails as $email) {
            $success = $this->gmailService->trashEmail($token, $email->gmail_message_id);

            if ($success) {
                $email->update(['gmail_trashed_at' => now()]);
                $trashed++;
            } else {
                $failed++;
            }
        }

        return [$trashed, $failed];
    }
}
