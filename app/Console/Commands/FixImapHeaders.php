<?php

namespace App\Console\Commands;

use App\Models\Email;
use Illuminate\Console\Command;

class FixImapHeaders extends Command
{
    protected $signature = 'emails:fix-imap-headers {--dry-run : 実行せず確認のみ}';
    protected $description = 'IMAP同期メールの文字化けヘッダーを再デコード';

    private $socket;
    private int $tagSeq = 0;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $emails = Email::where('gmail_message_id', 'like', 'imap-%')
            ->where(function ($q) {
                $q->where('from_name', 'like', '%???%')
                  ->orWhere('subject', 'like', '%???%');
            })->get(['id', 'gmail_message_id', 'from_name', 'subject']);

        $this->info("対象: {$emails->count()}件" . ($dryRun ? ' (DRY-RUN)' : ''));
        if ($emails->isEmpty()) return 0;

        if (!$this->connect()) {
            $this->error('IMAP接続失敗');
            return 1;
        }

        try {
            $this->imapCommand('SELECT INBOX');
            $updated = 0;

            foreach ($emails as $email) {
                $uid = (int) str_replace('imap-', '', $email->gmail_message_id);
                $resp = $this->imapCommand("UID FETCH {$uid} (BODY.PEEK[HEADER.FIELDS (FROM SUBJECT)])");
                $headerBlock = '';
                $inLiteral = false;
                foreach ($resp['lines'] as $line) {
                    if (!is_string($line)) continue;
                    if (preg_match('/\{(\d+)\}/', $line) && !$inLiteral) {
                        $inLiteral = true;
                        continue;
                    }
                    if ($inLiteral && $line !== ')') {
                        $headerBlock .= $line . "\n";
                    }
                }

                $headerBlock = preg_replace('/\r?\n[\t ]+/', ' ', $headerBlock);
                $headers = [];
                foreach (explode("\n", $headerBlock) as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    $pos = strpos($line, ':');
                    if ($pos === false) continue;
                    $key = strtolower(trim(substr($line, 0, $pos)));
                    $val = trim(substr($line, $pos + 1));
                    $headers[$key] = $val;
                }

                $newSubject = $this->decodeHeader($headers['subject'] ?? $email->subject);
                $fromRaw = $this->decodeHeader($headers['from'] ?? '');
                [$newFromName, $newFromAddress] = $this->parseFrom($fromRaw);

                $changes = [];
                if ($newFromName && $newFromName !== $email->from_name) {
                    $changes['from_name'] = $newFromName;
                }
                if ($newSubject !== $email->subject && !str_contains($newSubject, '???')) {
                    $changes['subject'] = $newSubject;
                }

                if (!empty($changes)) {
                    if (!$dryRun) {
                        Email::where('id', $email->id)->update($changes);
                    }
                    $updated++;
                    $this->line("#{$email->id}: " . implode(', ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($changes), $changes)));
                }
            }

            $this->info("完了: {$updated}件更新");
            return 0;
        } finally {
            $this->disconnect();
        }
    }

    private function decodeHeader(string $value): string
    {
        if (empty($value) || !str_contains($value, '=?')) return $value;
        return preg_replace_callback(
            '/=\?([^?]+)\?(B|Q)\?([^?]*)\?=/i',
            function ($m) {
                $charset  = $m[1];
                $encoding = strtoupper($m[2]);
                $text     = $m[3];
                $decoded  = $encoding === 'B'
                    ? base64_decode($text)
                    : quoted_printable_decode(str_replace('_', ' ', $text));
                return @mb_convert_encoding($decoded, 'UTF-8', $charset) ?: $decoded;
            },
            preg_replace('/\?=\s+=\?([^?]+)\?(B|Q)\?/i', '', $value)
        );
    }

    private function parseFrom(string $from): array
    {
        if (preg_match('/^(.*?)\s*<(.+?)>$/', $from, $m)) {
            return [trim($m[1], '"\''), $m[2]];
        }
        return [null, $from];
    }

    private function connect(): bool
    {
        $host = env('KAGOYA_POP3_HOST');
        $user = env('KAGOYA_POP3_USERNAME');
        $pass = env('KAGOYA_POP3_PASSWORD');
        $this->socket = @fsockopen("ssl://{$host}", 993, $errno, $errstr, 15);
        if (!$this->socket) return false;
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
        $inLiteral = false;
        $remaining = 0;
        while (true) {
            $line = fgets($this->socket, 8192);
            if ($line === false) break;
            $line = rtrim($line, "\r\n");

            if (!$inLiteral && preg_match('/\{(\d+)\}$/', $line, $m)) {
                $lines[] = $line;
                $remaining = (int) $m[1];
                $inLiteral = true;
                $data = '';
                while ($remaining > 0) {
                    $chunk = fread($this->socket, min($remaining, 8192));
                    if ($chunk === false) break;
                    $data .= $chunk;
                    $remaining -= strlen($chunk);
                }
                foreach (explode("\n", $data) as $dl) {
                    $lines[] = rtrim($dl, "\r");
                }
                $inLiteral = false;
                continue;
            }

            if (str_starts_with($line, "{$tag} ")) {
                return ['ok' => str_contains($line, 'OK'), 'line' => $line, 'lines' => $lines];
            }
            $lines[] = $line;
        }
        return ['ok' => false, 'line' => '', 'lines' => $lines];
    }
}
