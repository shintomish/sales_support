<?php

namespace App\Services;

use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use App\Models\Email;
use App\Models\EmailAttachment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class KagoyaMailService
{
    private $socket;
    private int $tagSeq = 0;

    /**
     * KAGOYA IMAP から直接メールを取得し emails テーブルに保存する
     */
    public function syncEmails(int $maxFetch = 100): int
    {
        $tenantId = 1;

        if (!$this->connect()) {
            return 0;
        }

        try {
            // SELECT INBOX
            $selectResp = $this->imapCommand('SELECT INBOX');
            $exists = 0;
            foreach ($selectResp['lines'] as $line) {
                if (preg_match('/\*\s+(\d+)\s+EXISTS/', $line, $m)) {
                    $exists = (int) $m[1];
                }
            }
            Log::info("[KagoyaIMAP] INBOX: {$exists}件");

            if ($exists === 0) return 0;

            // 最新 $maxFetch 件の UID を取得
            $start = max(1, $exists - $maxFetch + 1);
            $fetchResp = $this->imapCommand("FETCH {$start}:{$exists} (UID)");

            $uids = [];
            foreach ($fetchResp['lines'] as $line) {
                if (preg_match('/UID\s+(\d+)/', $line, $m)) {
                    $uids[] = (int) $m[1];
                }
            }

            // 既存 UID を一括チェック
            $existingUids = Email::where('tenant_id', $tenantId)
                ->whereIn('gmail_message_id', array_map(fn($u) => "imap-{$u}", $uids))
                ->pluck('gmail_message_id')
                ->map(fn($id) => str_replace('imap-', '', $id))
                ->toArray();

            $newUids = array_filter($uids, fn($u) => !in_array((string) $u, $existingUids));

            if (empty($newUids)) {
                Log::info("[KagoyaIMAP] 新着なし");
                return 0;
            }

            Log::info("[KagoyaIMAP] 新着: " . count($newUids) . "件");

            $stored = 0;
            // UID FETCH で本文取得（一件ずつ）
            foreach ($newUids as $uid) {
                try {
                    $result = $this->fetchMessageByUid($uid);
                    if (empty($result['body'])) continue;

                    $this->storeRawMessage($result['body'], $tenantId, "imap-{$uid}", $result['internaldate']);
                    $stored++;
                } catch (\Throwable $e) {
                    Log::warning("[KagoyaIMAP] UID {$uid} 処理失敗: " . $e->getMessage());
                    continue;
                }
            }

            Log::info("[KagoyaIMAP] 同期完了", ['stored' => $stored]);
            return $stored;
        } finally {
            $this->disconnect();
        }
    }

    private function storeRawMessage(string $raw, int $tenantId, string $uid, ?string $internalDate = null): void
    {
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
        $headerBlock = $parts[0] ?? '';
        $bodyRaw = $parts[1] ?? '';

        $headers = $this->parseHeaders($headerBlock);

        $subject = $this->decodeHeader($headers['subject'] ?? '(件名なし)');
        $from = $this->decodeHeader($headers['from'] ?? '');
        $to = $this->decodeHeader($headers['to'] ?? '');

        [$fromName, $fromAddress] = $this->parseFrom($from);

        // バウンスメール（不達通知）を除外
        $lcFrom = strtolower($fromAddress);
        $lcSubject = strtolower($subject);
        if (str_contains($lcFrom, 'mailer-daemon') ||
            str_contains($lcFrom, 'postmaster') ||
            str_contains($lcSubject, 'undelivered') ||
            str_contains($lcSubject, 'returned mail') ||
            str_contains($lcSubject, 'delivery status') ||
            str_contains($lcSubject, 'undeliverable') ||
            str_contains($lcSubject, 'failure notice') ||
            str_contains($lcSubject, 'mail delivery failed')) {
            return;
        }

        // INTERNALDATE（サーバー受信時刻）を優先、なければDateヘッダー
        $receivedAt = $internalDate
            ? Carbon::parse($internalDate)->utc()
            : ($headers['date'] ?? null
                ? Carbon::parse($headers['date'])->utc()
                : Carbon::now()->utc());

        $contentType = $headers['content-type'] ?? 'text/plain';
        $cte = strtolower($headers['content-transfer-encoding'] ?? '7bit');
        [$bodyText, $bodyHtml, $attachments] = $this->parseBody($bodyRaw, $contentType, $cte);

        $email = Email::create([
            'tenant_id'        => $tenantId,
            'gmail_message_id' => $uid,
            'thread_id'        => null,
            'subject'          => mb_substr($subject, 0, 255),
            'from_address'     => $fromAddress,
            'from_name'        => $fromName,
            'to_address'       => mb_substr($to, 0, 500),
            'body_text'        => $bodyText,
            'body_html'        => $bodyHtml,
            'received_at'      => $receivedAt,
            'is_read'          => false,
        ]);

        foreach ($attachments as $att) {
            EmailAttachment::create([
                'email_id'            => $email->id,
                'filename'            => $att['filename'],
                'mime_type'           => $att['mime_type'],
                'size'                => $att['size'],
                'gmail_attachment_id' => null,
            ]);
        }

        // 返信紐づけ
        $inReplyTo = trim($headers['in-reply-to'] ?? '');
        if ($inReplyTo) {
            $history = DeliverySendHistory::where('ses_message_id', $inReplyTo)
                ->whereNull('reply_email_id')
                ->first();

            if (!$history) {
                $clean = trim($inReplyTo, '<>');
                $history = DeliverySendHistory::where('ses_message_id', 'like', "%{$clean}%")
                    ->whereNull('reply_email_id')
                    ->first();
            }

            if ($history) {
                $history->update([
                    'reply_email_id' => $email->id,
                    'replied_at'     => $email->received_at,
                    'status'         => 'replied',
                ]);
                if ($history->campaign_id) {
                    DeliveryCampaign::where('id', $history->campaign_id)
                        ->increment('replied_count');
                }
                Log::info("[KagoyaIMAP] 返信紐づけ完了 history_id={$history->id} email_id={$email->id}");
            }
        }
    }

    // ── パース系 ────────────────────────────────────────

    private function parseHeaders(string $headerBlock): array
    {
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
        return $headers;
    }

    private function decodeHeader(string $value): string
    {
        if (empty($value) || !str_contains($value, '=?')) return $value;

        // 同一charset+encodingの連続エンコードワードを結合してからデコード
        // (RFC 2047のマルチバイト文字分割に対応)
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

    private function parseBody(string $bodyRaw, string $contentType, string $cte = '7bit'): array
    {
        $text = null;
        $html = null;
        $attachments = [];
        $ct = strtolower($contentType);

        if (str_contains($ct, 'multipart/')) {
            if (preg_match('/boundary="?([^";\s]+)"?/i', $contentType, $m)) {
                $boundary = $m[1];
                $parts = explode("--{$boundary}", $bodyRaw);
                array_shift($parts);

                foreach ($parts as $part) {
                    $part = ltrim($part, "\r\n");
                    if (str_starts_with($part, '--')) break;

                    $subParts = preg_split('/\r?\n\r?\n/', $part, 2);
                    $partHeaders = $this->parseHeaders($subParts[0] ?? '');
                    $partBody = $subParts[1] ?? '';
                    $partCt = $partHeaders['content-type'] ?? 'text/plain';
                    $partCte = strtolower($partHeaders['content-transfer-encoding'] ?? '7bit');
                    $partDisp = $partHeaders['content-disposition'] ?? '';

                    if (str_contains(strtolower($partCt), 'multipart/')) {
                        [$t, $h, $a] = $this->parseBody($partBody, $partCt, $partCte);
                        if ($t && !$text) $text = $t;
                        if ($h && !$html) $html = $h;
                        $attachments = array_merge($attachments, $a);
                        continue;
                    }

                    if (str_contains(strtolower($partDisp), 'attachment') ||
                        (str_contains(strtolower($partDisp), 'filename') && !str_contains(strtolower($partCt), 'text/'))) {
                        $filename = 'unknown';
                        if (preg_match('/filename="?([^";\r\n]+)"?/i', $partDisp . ';' . $partCt, $fm)) {
                            $filename = $this->decodeHeader(trim($fm[1]));
                        }
                        $decoded = $this->decodeBody($partBody, $partCte);
                        $attachments[] = [
                            'filename'  => $filename,
                            'mime_type' => trim(explode(';', $partCt)[0]),
                            'size'      => strlen($decoded),
                        ];
                        continue;
                    }

                    $decoded = $this->decodeBody($partBody, $partCte);
                    $charset = 'UTF-8';
                    if (preg_match('/charset="?([^";\s]+)"?/i', $partCt, $cm)) {
                        $charset = $cm[1];
                    }
                    $decoded = @mb_convert_encoding($decoded, 'UTF-8', $charset) ?: $decoded;

                    if (str_contains(strtolower($partCt), 'text/plain') && !$text) {
                        $text = $decoded;
                    } elseif (str_contains(strtolower($partCt), 'text/html') && !$html) {
                        $html = $decoded;
                    }
                }
            }
        } else {
            $decoded = $this->decodeBody($bodyRaw, $cte);
            $charset = 'UTF-8';
            if (preg_match('/charset="?([^";\s]+)"?/i', $contentType, $cm)) {
                $charset = $cm[1];
            }
            $decoded = @mb_convert_encoding($decoded, 'UTF-8', $charset) ?: $decoded;

            if (str_contains($ct, 'text/html')) {
                $html = $decoded;
            } else {
                $text = $decoded;
            }
        }

        return [$text, $html, $attachments];
    }

    private function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64'           => base64_decode($body),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }

    // ── IMAP 通信 ────────────────────────────────────────

    private function connect(): bool
    {
        $host = env('KAGOYA_POP3_HOST');
        $port = 993;
        $user = env('KAGOYA_POP3_USERNAME');
        $pass = env('KAGOYA_POP3_PASSWORD');

        $this->socket = @fsockopen("ssl://{$host}", $port, $errno, $errstr, 15);
        if (!$this->socket) {
            Log::error("[KagoyaIMAP] 接続失敗: {$errstr} ({$errno})");
            return false;
        }
        stream_set_timeout($this->socket, 30);
        fgets($this->socket); // greeting

        $resp = $this->imapCommand("LOGIN {$user} {$pass}");
        if (!$resp['ok']) {
            Log::error("[KagoyaIMAP] LOGIN 失敗");
            return false;
        }

        Log::info('[KagoyaIMAP] 接続成功');
        return true;
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
                return [
                    'ok'    => str_contains($line, 'OK'),
                    'line'  => $line,
                    'lines' => $lines,
                ];
            }
            $lines[] = $line;
        }
        return ['ok' => false, 'line' => '', 'lines' => $lines];
    }

    /**
     * @return array{body: string, internaldate: string|null}
     */
    private function fetchMessageByUid(int $uid): array
    {
        $tag = 'A' . (++$this->tagSeq);
        fwrite($this->socket, "{$tag} UID FETCH {$uid} (BODY.PEEK[] INTERNALDATE)\r\n");

        $data = '';
        $internalDate = null;
        $inBody = false;
        $remaining = 0;

        while (true) {
            $line = fgets($this->socket, 8192);
            if ($line === false) break;

            if (!$inBody) {
                // INTERNALDATE 検出: INTERNALDATE "23-Apr-2026 18:27:00 +0900"
                if (preg_match('/INTERNALDATE\s+"([^"]+)"/i', $line, $dm)) {
                    $internalDate = $dm[1];
                }
                // リテラルサイズ検出: * N FETCH (BODY[] {12345}
                if (preg_match('/\{(\d+)\}/', $line, $m)) {
                    $remaining = (int) $m[1];
                    $inBody = true;
                    // バイナリ読み取り
                    while ($remaining > 0) {
                        $chunk = fread($this->socket, min($remaining, 8192));
                        if ($chunk === false) break;
                        $data .= $chunk;
                        $remaining -= strlen($chunk);
                    }
                    continue;
                }
            }

            $trimmed = rtrim($line, "\r\n");
            if (str_starts_with($trimmed, "{$tag} ")) {
                break;
            }
            // ")" のみの行はFETCH終端
            if ($trimmed === ')' && $inBody) {
                continue;
            }
        }

        return ['body' => $data, 'internaldate' => $internalDate];
    }
}
