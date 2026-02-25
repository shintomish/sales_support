<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ClaudeService
{
    private string $apiKey;
    private string $apiUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key');
    }

    /**
     * 名刺OCRテキストから構造化データを抽出
     */
    public function extractBusinessCardInfo(string $ocrText): array
    {
        $prompt = $this->buildPrompt($ocrText);

        $response = Http::withHeaders([
            'anthropic-version' => '2023-06-01',
            'x-api-key' => $this->apiKey,
            'content-type' => 'application/json',
        ])->post($this->apiUrl, [
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 1024,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ]
            ],
        ]);

        if ($response->failed()) {
            throw new \Exception('Claude API error: ' . $response->body());
        }

        $result = $response->json();
        $content = $result['content'][0]['text'] ?? '';

        return $this->parseResponse($content);
    }

    private function buildPrompt(string $ocrText): string
    {
        return <<<PROMPT
以下は名刺から抽出したOCRテキストです。このテキストから、名刺の情報を抽出してJSON形式で返してください。

OCRテキスト:
{$ocrText}

以下のJSON形式で返してください。情報が無い場合はnullを設定してください：

{
  "company_name": "会社名",
  "person_name": "氏名",
  "department": "部署名",
  "position": "役職",
  "postal_code": "郵便番号",
  "address": "住所",
  "phone": "電話番号",
  "mobile": "携帯電話番号",
  "fax": "FAX番号",
  "email": "メールアドレス",
  "website": "ウェブサイトURL"
}

JSONのみを返してください。説明文は不要です。
PROMPT;
    }

    private function parseResponse(string $content): array
    {
        // JSONを抽出（```json ... ``` のような形式に対応）
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*$/', '', $content);
        $content = trim($content);

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse Claude API response as JSON');
        }

        return $data;
    }
}
