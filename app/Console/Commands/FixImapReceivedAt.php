<?php

namespace App\Console\Commands;

use App\Models\Email;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FixImapReceivedAt extends Command
{
    protected $signature = 'emails:fix-imap-received-at {--dry-run : 実行せず確認のみ}';
    protected $description = 'IMAP同期済みメールのreceived_atをINTERNALDATEで修正';

    private $socket;
    private int $tagSeq = 0;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // IMAP同期メールのUIDを取得
        $emails = Email::where('gmail_message_id', 'like', 'imap-%')
            ->select('id', 'gmail_message_id', 'received_at')
            ->orderBy('id')
            ->get();

        $this->info("対象: {$emails->count()}件" . ($dryRun ? ' (DRY-RUN)' : ''));

        if ($emails->isEmpty()) return 0;

        // UIDリストを抽出
        $uidMap = [];
        foreach ($emails as $email) {
            $uid = (int) str_replace('imap-', '', $email->gmail_message_id);
            $uidMap[$uid] = $email;
        }

        // IMAP接続
        if (!$this->connect()) {
            $this->error('IMAP接続失敗');
            return 1;
        }

        try {
            $selectResp = $this->imapCommand('SELECT INBOX');
            foreach ($selectResp['lines'] as $line) {
                if (preg_match('/\*\s+(\d+)\s+EXISTS/', $line, $m)) {
                    $this->info("INBOX: {$m[1]}件");
                }
            }

            // UIDをバッチ処理（500件ずつ）
            $allUids = array_keys($uidMap);
            $chunks = array_chunk($allUids, 500);
            $updated = 0;
            $skipped = 0;

            foreach ($chunks as $chunkIndex => $chunk) {
                $uidSet = implode(',', $chunk);
                $resp = $this->imapCommand("UID FETCH {$uidSet} (UID INTERNALDATE)");

                foreach ($resp['lines'] as $line) {
                    if (preg_match('/UID\s+(\d+)/', $line, $um) &&
                        preg_match('/INTERNALDATE\s+"([^"]+)"/i', $line, $dm)) {
                        $uid = (int) $um[1];
                        $internalDate = $dm[1];

                        if (!isset($uidMap[$uid])) continue;

                        $email = $uidMap[$uid];
                        $newReceivedAt = Carbon::parse($internalDate)->utc();

                        if ($email->received_at == $newReceivedAt->toDateTimeString()) {
                            $skipped++;
                            continue;
                        }

                        if (!$dryRun) {
                            $email->update(['received_at' => $newReceivedAt]);
                        }
                        $updated++;
                    }
                }

                $this->info("バッチ " . ($chunkIndex + 1) . "/" . count($chunks) . " 完了 (更新: {$updated}, スキップ: {$skipped})");
            }

            $this->info("完了: 更新={$updated}, スキップ={$skipped}");
            return 0;
        } finally {
            $this->disconnect();
        }
    }

    private function connect(): bool
    {
        $host = env('KAGOYA_POP3_HOST');
        $user = env('KAGOYA_POP3_USERNAME');
        $pass = env('KAGOYA_POP3_PASSWORD');

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
