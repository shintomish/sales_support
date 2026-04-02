<?php

namespace App\Services;

use App\Models\Email;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\EmailMatchPreviewService;

class EmailExtractionService
{
    private string $apiKey;
    private string $apiUrl = 'https://api.anthropic.com/v1/messages';

    // ── URLフィルタ設定 ─────────────────────────────────────────

    /** 無効と判断するドメインパターン（部分一致） */
    private const BLOCKED_DOMAINS = [
        // メールマガジン配信ASP
        'cuenote.jp',
        'bme.jp',
        'hm-f.jp',
        'k3r.jp',
        'blastmail.jp',
        'shanon.co.jp',
        'mail-magazine.co.jp',
        'neosol.co.jp',
        'umb.jp',
        'asp.ne.jp',
        // 海外メール配信
        'mailchimp.com',
        'sendgrid.net',
        'mandrillapp.com',
        'mailgun.org',
        'constantcontact.com',
        'benchmark.email',
        'benchmarkemail.com',
        // SNS・コミュニケーション（案件情報なし）
        'line.me',
        'facebook.com',
        'instagram.com',
        'twitter.com',
        'x.com',
        // 日程調整ツール
        'timerex.net',
        'calendly.com',
        'spir.app',
        // Google（SSR非対応・認証必須）
        'docs.google.com',
        'drive.google.com',
        // 広告・トラッキング
        'amazon-adsystem.com',
        'doubleclick.net',
        'google-analytics.com',
        'googletagmanager.com',
        // メールリスト管理
        'maillist-manage.jp',
        'maillist-manage.com',
        // Google静的ファイル・ストレージ
        'storage.googleapis.com',
        'googleapis.com',
    ];

    /** 無効と判断するパスパターン（部分一致） */
    private const BLOCKED_PATHS = [
        '/unsubscribe',
        '/optout',
        '/opt-out',
        '/track/click',
        '/track/open',
        '/click/',
        '/open/',
        '/wf/click',
        '/wf/open',
        '?utm_',
    ];

    // ── fetch時のUser-Agent ─────────────────────────────────────
    private const USER_AGENT = 'Mozilla/5.0 (compatible; SalesSupportBot/1.0)';

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key');
    }

    /**
     * 分類済み・未抽出のメールを一括処理
     * @return int 処理件数
     */
    public function extractPending(): int
    {
        // category設定済み かつ extracted_data に result キーがまだないもの
        $emails = Email::whereNotNull('category')
            ->where(function ($q) {
                $q->whereNull('extracted_data')
                  ->orWhereRaw("extracted_data->>'result' IS NULL");
            })
            ->orderBy('received_at')
            ->limit(20)  // 1バッチ最大20件（API制限対策）
            ->get();

        $count = 0;
        foreach ($emails as $email) {
            try {
                $this->extract($email);
                $count++;
                // API レート制限を考慮して少し待機
                usleep(500000); // 0.5秒
            } catch (\Throwable $e) {
                Log::error("[EmailExtraction] email_id={$email->id} 失敗: " . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * 1件のメールからClaude APIで情報を抽出してDBを更新する
     */
    public function extract(Email $email): void
    {
        $existing = $email->extracted_data ?? [];

        // 1. 有効URLを選別してコンテンツ取得
        $urls        = $existing['urls'] ?? [];
        $validUrls   = $this->filterValidUrls($urls);
        $fetchedText = $this->fetchBestUrl($validUrls);

        // 2. 抽出ソース決定（URL取得成功 > メール本文）
        $sourceText = $fetchedText ?? strip_tags($email->body_html ?? '') ?: $email->body_text ?? '';
        $sourceText = $this->truncate($sourceText, 4000);

        if (empty(trim($sourceText))) {
            Log::info("[EmailExtraction] email_id={$email->id} テキストなし・スキップ");
            return;
        }

        // 3. Claude APIで抽出
        $result = $this->callClaude($sourceText, $email->category, $email->subject ?? '');

        // 4. extracted_data を更新（既存キーを保持しつつ追記）
        $email->update([
            'extracted_data' => array_merge($existing, [
                'valid_urls'    => $validUrls,
                'source'        => $fetchedText ? 'url' : 'body',
                'result'        => $result,
                'extracted_at'  => Carbon::now()->toIso8601String(),
            ]),
        ]);

        // 5. マッチングスコアを自動計算してDBに保存（左ペイン色分け用）
        if (!($result['parse_error'] ?? false)) {
            try {
                app(EmailMatchPreviewService::class)->previewAndStore($email->fresh());
            } catch (\Throwable $e) {
                Log::warning("[EmailExtraction] match preview store failed email_id={$email->id}: " . $e->getMessage());
            }
        }
    }

    // ── URLフィルタリング ────────────────────────────────────────

    /**
     * トラッキングURL・配信管理URLを除外して有効なURLのみ返す
     */
    public function filterValidUrls(array $urls): array
    {
        $valid = [];

        foreach ($urls as $url) {
            if ($this->isValidUrl($url)) {
                $valid[] = $url;
            }
        }

        return array_values(array_unique($valid));
    }

    private function isValidUrl(string $url): bool
    {
        // 不正形式URL（"http://https://" 等）を除外
        if (!preg_match('#^https?://#i', $url) || str_starts_with($url, 'http://https://')) {
            return false;
        }

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            return false;
        }

        $host = strtolower($parsed['host']);
        $path = strtolower($parsed['path'] ?? '');
        $query = strtolower($parsed['query'] ?? '');

        // ブロックドメイン判定
        foreach (self::BLOCKED_DOMAINS as $blocked) {
            if (str_contains($host, $blocked)) {
                return false;
            }
        }

        // ブロックパス判定
        $fullPath = $path . ($query ? '?' . $query : '');
        foreach (self::BLOCKED_PATHS as $blockedPath) {
            if (str_contains($fullPath, $blockedPath)) {
                return false;
            }
        }

        // 会社トップページ（パスなし or "/" のみ）→ 案件情報なし
        if ($path === '' || $path === '/') {
            return false;
        }

        // ランダム文字列が長い短縮URL的なパスを排除
        // パスが /[英数字20文字以上] だけの場合はトラッキングURL疑い
        if (preg_match('#^/[a-zA-Z0-9_\-]{20,}$#', $path)) {
            return false;
        }

        return true;
    }

    // ── URLコンテンツ取得 ────────────────────────────────────────

    /**
     * 有効URLリストの先頭から順にfetchし、最初に成功したテキストを返す
     * @return string|null
     */
    private function fetchBestUrl(array $urls): ?string
    {
        foreach ($urls as $url) {
            $text = $this->fetchUrlText($url);
            if ($text && mb_strlen($text) > 200) {
                return $text;
            }
        }
        return null;
    }

    private function fetchUrlText(string $url): ?string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(10)
                ->get($url);

            if ($response->failed()) {
                return null;
            }

            $html = $response->body();

            // <script> <style> タグを除去
            $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
            $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);

            // HTMLタグ除去・空白正規化
            $text = strip_tags($html);
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);

            return mb_strlen($text) > 50 ? $text : null;

        } catch (\Throwable $e) {
            Log::warning("[EmailExtraction] URL fetch失敗: {$url} / " . $e->getMessage());
            return null;
        }
    }

    // ── Claude API呼び出し ───────────────────────────────────────

    private function callClaude(string $content, string $category, string $subject): array
    {
        $prompt = $category === 'engineer'
            ? $this->buildEngineerPrompt($content, $subject)
            : $this->buildProjectPrompt($content, $subject);

        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(30)->post($this->apiUrl, [
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 1024,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        if ($response->failed()) {
            throw new \Exception('Claude API error: ' . $response->body());
        }

        $text = $response->json('content.0.text', '');

        return $this->parseJson($text);
    }

    private function buildProjectPrompt(string $content, string $subject): string
    {
        return <<<PROMPT
以下はSES案件に関するメール・Webページのテキストです。
件名: {$subject}

本文:
{$content}

このテキストから案件情報を抽出してJSONで返してください。
情報が読み取れない項目はnullにしてください。
単価は万円/月の数値のみ（例: 65）。
work_styleはremote/office/hybridのいずれか。

{
  "title": "案件タイトル",
  "description": "案件概要（200字以内）",
  "end_client": "エンドクライアント名またはnull",
  "skills": ["スキル1", "スキル2"],
  "unit_price_min": 下限単価（数値またはnull）,
  "unit_price_max": 上限単価（数値またはnull）,
  "contract_type": "準委任/派遣/請負/null",
  "contract_period_months": 期間（月数・数値またはnull）,
  "start_date": "YYYY-MM-DDまたはnull",
  "work_location": "勤務地またはnull",
  "nearest_station": "最寄駅またはnull",
  "work_style": "remote/office/hybrid/null",
  "remote_frequency": "リモート頻度の説明またはnull",
  "required_experience_years": 必要経験年数（数値またはnull）,
  "interview_count": 面談回数（数値またはnull）
}

JSONのみ返してください。
PROMPT;
    }

    private function buildEngineerPrompt(string $content, string $subject): string
    {
        return <<<PROMPT
以下はSES技術者のスペックシートまたは技術者紹介メールのテキストです。
件名: {$subject}

本文:
{$content}

このテキストから技術者情報を抽出してJSONで返してください。
情報が読み取れない項目はnullにしてください。
単価は万円/月の数値のみ（例: 65）。
work_styleはremote/office/hybridのいずれか。

{
  "name": "氏名またはnull",
  "skills": ["スキル1", "スキル2"],
  "experience_years": 総経験年数（数値またはnull）,
  "desired_unit_price_min": 希望単価下限（数値またはnull）,
  "desired_unit_price_max": 希望単価上限（数値またはnull）,
  "available_from": "YYYY-MM-DDまたはnull",
  "work_style": "remote/office/hybrid/null",
  "preferred_location": "希望勤務地またはnull",
  "self_introduction": "スキルサマリー（200字以内）"
}

JSONのみ返してください。
PROMPT;
    }

    // ── ユーティリティ ───────────────────────────────────────────

    private function parseJson(string $text): array
    {
        $text = preg_replace('/^```json\s*/m', '', $text);
        $text = preg_replace('/^```\s*$/m', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('[EmailExtraction] JSONパース失敗: ' . $text);
            return ['parse_error' => true, 'raw' => mb_substr($text, 0, 500)];
        }

        return $data;
    }

    private function truncate(string $text, int $maxChars): string
    {
        return mb_strlen($text) > $maxChars
            ? mb_substr($text, 0, $maxChars) . '...'
            : $text;
    }
}
