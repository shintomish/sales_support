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
     * 提案メール草稿を生成
     */
    public function generateProposal(array $mail, array $engineer): array
    {
        $prompt = $this->buildProposalPrompt($mail, $engineer);

        $response = Http::withHeaders([
            'anthropic-version' => '2023-06-01',
            'x-api-key'         => $this->apiKey,
            'content-type'      => 'application/json',
        ])->timeout(30)->post($this->apiUrl, [
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 1024,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        if ($response->failed()) {
            throw new \Exception('Claude API error: ' . $response->body());
        }

        $text = $response->json('content.0.text', '');

        // subject / body を分割して返す
        if (preg_match('/【件名】\s*(.+?)(?:\n|$)/u', $text, $sm)) {
            $subject = trim($sm[1]);
        } else {
            $subject = 'Re: ' . ($mail['email_subject'] ?? '案件のご紹介');
        }
        $body = preg_replace('/^.*?【本文】\s*/su', '', $text);

        return [
            'subject'    => $subject,
            'body'       => trim($body),
            'to_address' => $mail['from_address'] ?? '',
            'to_name'    => $mail['from_name']    ?? '',
        ];
    }

    private function buildProposalPrompt(array $mail, array $engineer): string
    {
        $skills = collect($engineer['skills'] ?? [])
            ->map(fn($s) => $s['name'] . ($s['experience_years'] ? "（{$s['experience_years']}年）" : ''))
            ->implode('、');

        $price = '';
        if (!empty($engineer['desired_unit_price_min'])) {
            $price = $engineer['desired_unit_price_min'] . '〜' . ($engineer['desired_unit_price_max'] ?? '?') . '万円/月';
        }

        $availability = match($engineer['availability_status'] ?? '') {
            'available'   => '即日稼働可能',
            'scheduled'   => '稼働予定（' . ($engineer['available_from'] ?? '') . '〜）',
            'working'     => '現在稼働中・' . ($engineer['available_from'] ?? '要相談') . '〜',
            default       => '要相談',
        };

        $mailTitle    = $mail['title']         ?? $mail['email_subject'] ?? '案件';
        $mailSkills   = implode('、', $mail['required_skills'] ?? []);
        $mailLocation = $mail['work_location'] ?? '';
        $mailPrice    = '';
        if (!empty($mail['unit_price_min'])) {
            $mailPrice = $mail['unit_price_min'] . '〜' . ($mail['unit_price_max'] ?? '?') . '万円';
        }
        $toName       = $mail['sales_contact'] ?? $mail['from_name'] ?? 'ご担当者';

        return <<<PROMPT
あなたはSES企業の営業担当です。以下の案件に対して、技術者を提案する返信メールを日本語で作成してください。

## 案件情報
- タイトル: {$mailTitle}
- 必須スキル: {$mailSkills}
- 勤務地: {$mailLocation}
- 単価: {$mailPrice}

## 提案する技術者
- 氏名: {$engineer['name']}
- 年齢: {$engineer['age']}歳
- スキル: {$skills}
- 稼働: {$availability}
- 希望単価: {$price}
- 所属: {$engineer['affiliation']}

## 指示
- 必ず以下の書き出しで始めること（変更禁止）:
  {$toName} 様

  いつもお世話になっております。
  株式会社アイゼン・ソリューションのSES営業担当です。

- 書き出しに続けて、技術者の紹介・アピールポイントを自然に盛り込む
- 面談打診で締める
- 書き出し含め250〜350文字程度
- 以下の形式で出力すること:

【件名】Re: {$mailTitle}

【本文】
（ここにメール本文）
PROMPT;
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
