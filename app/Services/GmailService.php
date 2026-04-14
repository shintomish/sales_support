<?php

namespace App\Services;

use App\Models\DeliverySendHistory;
use App\Models\GmailToken;
use App\Models\Email;
use App\Models\EmailAttachment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GmailService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $tokenUrl = 'https://oauth2.googleapis.com/token';
    private string $apiBase  = 'https://gmail.googleapis.com/gmail/v1';

    public function __construct()
    {
        $this->clientId     = config('services.gmail.client_id');
        $this->clientSecret = config('services.gmail.client_secret');
        $this->redirectUri  = config('services.gmail.redirect_uri');
    }

    // ── OAuth ──────────────────────────────────────────
    public function getAuthUrl(int $userId): string
    {
        $params = http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/gmail.modify email profile',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $userId,  // ← 追加
        ]);
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post($this->tokenUrl, [
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if ($response->failed()) {
            throw new \Exception('Gmail token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    public function refreshAccessToken(GmailToken $gmailToken): string
    {
        $response = Http::asForm()->post($this->tokenUrl, [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $gmailToken->refresh_token,
            'grant_type'    => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new \Exception('Gmail token refresh failed: ' . $response->body());
        }

        $data = $response->json();
        $gmailToken->update([
            'access_token'    => $data['access_token'],
            'token_expires_at'=> Carbon::now()->addSeconds($data['expires_in'] - 60),
        ]);

        return $data['access_token'];
    }

    private function getValidAccessToken(GmailToken $gmailToken): string
    {
        if ($gmailToken->token_expires_at && Carbon::now()->lt($gmailToken->token_expires_at)) {
            return $gmailToken->access_token;
        }
        return $this->refreshAccessToken($gmailToken);
    }

    // ── メール取得 ──────────────────────────────────────

    public function fetchAndStoreEmails(GmailToken $gmailToken, int $maxResults = 50): int
    {
        $accessToken = $this->getValidAccessToken($gmailToken);

        // メッセージ一覧取得
        $listResponse = Http::withToken($accessToken)
            ->get("{$this->apiBase}/users/me/messages", [
                'maxResults' => $maxResults,
                'q'          => 'in:inbox',
            ]);

        if ($listResponse->failed()) {
            throw new \Exception('Gmail list failed: ' . $listResponse->body());
        }

        $messages = $listResponse->json('messages', []);
        $stored   = 0;

        foreach ($messages as $msg) {
            $gmailId = $msg['id'];

            // 既存チェック
            if (Email::where('gmail_message_id', $gmailId)->exists()) {
                continue;
            }

            // 詳細取得
            $detail = Http::withToken($accessToken)
                ->get("{$this->apiBase}/users/me/messages/{$gmailId}", [
                    'format' => 'full',
                ]);

            if ($detail->failed()) {
                Log::warning("Gmail message fetch failed: {$gmailId}");
                continue;
            }

            $this->storeEmail($detail->json(), $gmailToken->tenant_id);
            $stored++;
        }

        return $stored;
    }

    private function storeEmail(array $data, int $tenantId): void
    {
        $headers  = collect($data['payload']['headers'] ?? []);
        $subject  = $headers->firstWhere('name', 'Subject')['value'] ?? '(件名なし)';
        $from     = $headers->firstWhere('name', 'From')['value'] ?? '';
        $to       = $headers->firstWhere('name', 'To')['value'] ?? '';
        $dateStr  = $headers->firstWhere('name', 'Date')['value'] ?? null;

	[$fromName, $fromAddress] = $this->parseFrom($from);

        $receivedAt = isset($data['internalDate'])
            ? Carbon::createFromTimestampMs((int)$data['internalDate'])->utc()
            : ($dateStr ? Carbon::parse($dateStr)->utc() : Carbon::now()->utc());

        [$bodyText, $bodyHtml] = $this->extractBody($data['payload']);
        $attachments = $this->extractAttachments($data['payload']);

        $email = Email::create([
            'tenant_id'        => $tenantId,
            'gmail_message_id' => $data['id'],
            'thread_id'        => $data['threadId'] ?? null,
            'subject'          => mb_substr($subject, 0, 255),
            'from_address'     => $fromAddress,
            'from_name'        => $fromName,
            'to_address'       => $to,
            'body_text'        => $bodyText,
            'body_html'        => $bodyHtml,
            'received_at'      => $receivedAt,
            'is_read'          => !in_array('UNREAD', $data['labelIds'] ?? []),
        ]);

        foreach ($attachments as $att) {
            EmailAttachment::create([
                'email_id'            => $email->id,
                'filename'            => $att['filename'],
                'mime_type'           => $att['mime_type'],
                'size'                => $att['size'],
                'gmail_attachment_id' => $att['gmail_attachment_id'],
            ]);
        }

        // 返信紐づけ: In-Reply-To ヘッダーで送信履歴にリンク
        $inReplyTo = $headers->firstWhere('name', 'In-Reply-To')['value'] ?? null;
        if ($inReplyTo) {
            // ① Message-ID完全一致
            $history = DeliverySendHistory::where('ses_message_id', trim($inReplyTo))
                ->whereNull('reply_email_id')
                ->first();

            // ② SMTPリレーがMessage-IDを書き換えた場合のフォールバック:
            //    差出人メール + 件名（Re:除去）で最新の送信履歴を探す
            if (!$history && $fromAddress) {
                $originalSubject = trim(preg_replace('/^(Re:\s*|RE:\s*|Fwd:\s*|FW:\s*)*/iu', '', $subject));
                if ($originalSubject) {
                    $history = DeliverySendHistory::where('email', $fromAddress)
                        ->whereNull('reply_email_id')
                        ->where('status', 'sent')
                        ->whereHas('campaign', fn($q) => $q->where('subject', 'like', '%' . $originalSubject . '%'))
                        ->latest()
                        ->first();
                    if ($history) {
                        Log::info("[GmailSync] フォールバック紐づけ(件名+差出人) history_id={$history->id} email_id={$email->id}");
                    }
                }
            }

            if ($history) {
                $history->update([
                    'reply_email_id' => $email->id,
                    'replied_at'     => $email->received_at,
                    'status'         => 'replied',
                ]);
                // キャンペーンの返信数をインクリメント
                if ($history->campaign_id) {
                    \App\Models\DeliveryCampaign::where('id', $history->campaign_id)
                        ->increment('replied_count');
                }
                Log::info("[GmailSync] 返信紐づけ完了 history_id={$history->id} email_id={$email->id}");
            }
        }
    }

    private function parseFrom(string $from): array
    {
        if (preg_match('/^(.*?)\s*<(.+?)>$/', $from, $m)) {
            return [trim($m[1], '"\''), $m[2]];
        }
        return [null, $from];
    }

    private function extractBody(array $payload): array
    {
        $text = null;
        $html = null;

        $mimeType = $payload['mimeType'] ?? '';

        // 添付ファイルパートはスキップ
        if (!empty($payload['filename'])) {
            return [$text, $html];
        }

        if ($mimeType === 'text/plain') {
            $text = base64_decode(strtr($payload['body']['data'] ?? '', '-_', '+/'));
        } elseif ($mimeType === 'text/html') {
            $html = base64_decode(strtr($payload['body']['data'] ?? '', '-_', '+/'));
        }

        // マルチパート
        foreach ($payload['parts'] ?? [] as $part) {
            [$t, $h] = $this->extractBody($part);
            if ($t && !$text) $text = $t;
            if ($h && !$html) $html = $h;
        }

        return [$text, $html];
    }

    private function extractAttachments(array $payload): array
    {
        $attachments = [];

        // filenameがあり attachmentId があるパートが添付ファイル
        if (!empty($payload['filename']) && !empty($payload['body']['attachmentId'])) {
            $attachments[] = [
                'filename'            => $payload['filename'],
                'mime_type'           => $payload['mimeType'] ?? null,
                'size'                => $payload['body']['size'] ?? null,
                'gmail_attachment_id' => $payload['body']['attachmentId'],
            ];
        }

        // マルチパートを再帰的に探索
        foreach ($payload['parts'] ?? [] as $part) {
            $attachments = array_merge($attachments, $this->extractAttachments($part));
        }

        return $attachments;
    }

    // 添付ファイルのバイナリデータを取得
    public function fetchAttachmentData(GmailToken $gmailToken, string $messageId, string $attachmentId): string
    {
        $accessToken = $this->getValidAccessToken($gmailToken);

        $response = Http::withToken($accessToken)
            ->get("{$this->apiBase}/users/me/messages/{$messageId}/attachments/{$attachmentId}");

        if ($response->failed()) {
            throw new \Exception('Gmail attachment fetch failed: ' . $response->body());
        }

        $data = $response->json('data', '');

        // Gmail APIはURL-safe Base64で返す
        return base64_decode(strtr($data, '-_', '+/'));
    }

    // メール既読にする
    public function markAsRead(GmailToken $gmailToken, string $gmailMessageId): void
    {
        $accessToken = $this->getValidAccessToken($gmailToken);

        Http::withToken($accessToken)
            ->post("{$this->apiBase}/users/me/messages/{$gmailMessageId}/modify", [
                'removeLabelIds' => ['UNREAD'],
            ]);
    }

    // メールをゴミ箱に移動
    public function trashEmail(GmailToken $gmailToken, string $gmailMessageId): bool
    {
        $accessToken = $this->getValidAccessToken($gmailToken);

        $response = Http::withToken($accessToken)
            ->post("{$this->apiBase}/users/me/messages/{$gmailMessageId}/trash");

        if ($response->failed()) {
            Log::warning("[GmailService] ゴミ箱移動失敗 messageId={$gmailMessageId}: " . $response->body());
            return false;
        }

        return true;
    }
}
