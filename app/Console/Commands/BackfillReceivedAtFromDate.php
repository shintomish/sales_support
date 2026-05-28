<?php

namespace App\Console\Commands;

use App\Models\Email;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * 既存 IMAP 取込メールの received_at を Date ヘッダーで再設定する backfill。
 *
 * 背景: 配送遅延時に INTERNALDATE が大きく後ろにズレるため、メーラー上の送信時刻
 * (= Date ヘッダー) と一致させたい。--days で対象期間を絞り込む（既定 7日）。
 */
class BackfillReceivedAtFromDate extends Command
{
    protected $signature = 'emails:backfill-received-at-from-date {--days=7 : 直近何日分を対象にするか} {--dry-run : 実行せず確認のみ}';
    protected $description = 'IMAP同期メールのreceived_atをDateヘッダーで修正(配送遅延に強い)';

    private $socket;
    private int $tagSeq = 0;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days   = (int) $this->option('days');
        if ($days <= 0) {
            $this->error('--days は1以上を指定してください');
            return 1;
        }

        $threshold = now()->subDays($days);
        $emails = Email::where('gmail_message_id', 'like', 'imap-%')
            ->where('received_at', '>=', $threshold)
            ->select('id', 'gmail_message_id', 'received_at')
            ->orderBy('id')
            ->get();

        $this->info("対象: {$emails->count()}件 (直近{$days}日)" . ($dryRun ? ' (DRY-RUN)' : ''));
        if ($emails->isEmpty()) return 0;

        $uidMap = [];
        foreach ($emails as $email) {
            $uid = (int) str_replace('imap-', '', $email->gmail_message_id);
            $uidMap[$uid] = $email;
        }

        if (!$this->connect()) {
            $this->error('IMAP接続失敗');
            return 1;
        }

        try {
            $this->imapCommand('SELECT INBOX');

            $allUids = array_keys($uidMap);
            $chunks  = array_chunk($allUids, 100);
            $updated = 0;
            $skipped = 0;
            $missing = 0;

            foreach ($chunks as $chunkIndex => $chunk) {
                $uidSet = implode(',', $chunk);
                $resp = $this->imapCommand("UID FETCH {$uidSet} (UID BODY.PEEK[HEADER.FIELDS (DATE)])");

                $currentUid = null;
                $currentDate = null;
                foreach ($resp['lines'] as $line) {
                    if (preg_match('/UID\s+(\d+)/', $line, $um)) {
                        if ($currentUid !== null && $currentDate !== null) {
                            $this->applyUpdate($uidMap, $currentUid, $currentDate, $dryRun, $updated, $skipped, $missing);
                        }
                        $currentUid  = (int) $um[1];
                        $currentDate = null;
                    }
                    if (preg_match('/^Date:\s*(.+)$/i', $line, $dm)) {
                        $currentDate = trim($dm[1]);
                    }
                }
                if ($currentUid !== null && $currentDate !== null) {
                    $this->applyUpdate($uidMap, $currentUid, $currentDate, $dryRun, $updated, $skipped, $missing);
                }

                $this->info("バッチ " . ($chunkIndex + 1) . "/" . count($chunks) . " 完了 (更新:{$updated}, スキップ:{$skipped}, Date無し:{$missing})");
            }

            $this->info("完了: 更新={$updated}, スキップ={$skipped}, Date無し={$missing}");
            return 0;
        } finally {
            $this->disconnect();
        }
    }

    private function applyUpdate(array $uidMap, int $uid, string $dateHeader, bool $dryRun, int &$updated, int &$skipped, int &$missing): void
    {
        if (!isset($uidMap[$uid])) return;
        $email = $uidMap[$uid];

        try {
            $newReceivedAt = Carbon::parse($dateHeader)->utc();
        } catch (\Throwable $e) {
            $missing++;
            return;
        }

        if ($email->received_at == $newReceivedAt->toDateTimeString()) {
            $skipped++;
            return;
        }

        if (!$dryRun) {
            $email->update(['received_at' => $newReceivedAt]);
        }
        $updated++;
    }

    private function connect(): bool
    {
        $host = config('services.kagoya_pop3.host');
        $user = config('services.kagoya_pop3.username');
        $pass = config('services.kagoya_pop3.password');

        $this->socket = @fsockopen("ssl://{$host}", 993, $errno, $errstr, 15);
        if (!$this->socket) {
            return false;
        }
        stream_set_timeout($this->socket, 60);
        fgets($this->socket);

        $resp = $this->imapCommand("LOGIN {$user} {$pass}");
        return $resp['ok'];
    }

    private function disconnect(): void
    {
        if ($this->socket) {
            $this->imapCommand('LOGOUT');
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function imapCommand(string $cmd): array
    {
        $tag = 'A' . (++$this->tagSeq);
        fwrite($this->socket, "{$tag} {$cmd}\r\n");

        $lines = [];
        while (true) {
            $line = fgets($this->socket);
            if ($line === false) break;
            $line = rtrim($line, "\r\n");
            if (str_starts_with($line, "{$tag} ")) {
                return ['ok' => str_contains($line, 'OK'), 'line' => $line, 'lines' => $lines];
            }
            $lines[] = $line;
        }
        return ['ok' => false, 'line' => '', 'lines' => $lines];
    }
}
