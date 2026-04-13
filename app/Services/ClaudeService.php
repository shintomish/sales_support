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

        // 件名：【技術者ご紹介】{タイトル}（max{単価}万円）
        $title    = $mail['title'] ?? $mail['email_subject'] ?? '案件';
        $priceStr = '';
        $priceMax = isset($mail['unit_price_max']) ? (int) $mail['unit_price_max'] : 0;
        $priceMin = isset($mail['unit_price_min']) ? (int) $mail['unit_price_min'] : 0;
        if ($priceMax > 0) {
            $priceStr = '（max' . $priceMax . '万円）';
        } elseif ($priceMin > 0) {
            $priceStr = '（' . $priceMin . '万円〜）';
        }
        $subject = '【技術者ご紹介】' . $title . $priceStr;

        // 本文：【本文】以降を抽出
        $body = preg_replace('/^.*?【本文】\s*/su', '', $text);

        return [
            'subject'    => $subject,
            'body'       => trim($body),
            'to_address' => $mail['from_address'] ?? '',
            'to_name'    => $mail['sales_contact'] ?? $mail['from_name'] ?? '',
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

        $age = !empty($engineer['age']) ? (int) $engineer['age'] : null;
        $nameWithSuffix = $engineer['name']
            ? $engineer['name'] . ($age ? "（{$age}歳）" : '') . '氏'
            : '技術者';

        return <<<PROMPT
あなたはSES企業の積極的な営業担当です。以下の案件に対して、技術者を提案するメールの「本文パート」のみを日本語で作成してください。

## 案件情報
- タイトル: {$mailTitle}
- 必須スキル: {$mailSkills}
- 勤務地: {$mailLocation}
- 単価: {$mailPrice}

## 提案する技術者
- 氏名（表記）: {$nameWithSuffix}
- スキル: {$skills}
- 稼働: {$availability}
- 希望単価: {$price}
- 所属: {$engineer['affiliation']}

## 絶対に守るべき指示
- 挨拶文・署名は不要（別途テンプレートで付与するため）
- 本文の冒頭に必ず「{$nameWithSuffix}のご紹介です。」という一文を入れること
- スキル経験が浅い・0年でも、絶対に謝罪・遠慮・ネガティブな表現を使わないこと
- スキルが乏しい場合は「習得意欲・成長速度・コミュニケーション力・ポテンシャル」を前面に出して積極的にアピールすること
- 面談を強くプッシュする締めにすること
- 1文ごとに改行を入れ、読みやすい段落構造にすること
- 全体150〜200文字程度
- 以下の形式で出力すること:

【本文】
（技術者紹介・面談打診のみ。謝罪・ネガティブ表現禁止）
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

    /**
     * スキルシートテキストから技術者情報を抽出
     */
    public function extractSkillSheetInfo(string $text): array
    {
        $prompt = <<<PROMPT
以下はSESエンジニアのスキルシート（Excel/PDF/Word）から抽出したテキストです。
スキルシートは表形式で、「ラベル名 値」が同一行または隣接セルに並ぶ形式です。

テキスト:
{$text}

以下のJSON形式で返してください。情報が無い場合はnullを設定してください：

{
  "name": "氏名またはイニシャル（例: 山田太郎 / S.N / A.S）",
  "name_kana": "フリガナ（カタカナ）",
  "age": 年齢（整数。「28歳」「満36歳」なども数値のみ抽出。nullも可）,
  "gender": "性別（male/female/other/unanswered のいずれか、または null）",
  "email": "メールアドレス",
  "phone": "電話番号",
  "nearest_station": "最寄駅（路線名は除き駅名のみ。例: 姪浜駅 / 羽生駅 / 錦糸町駅）",
  "affiliation": "所属会社名",
  "affiliation_type": "所属区分（self/first_sub/bp/bp_member/contract/freelance/joining/hiring のいずれか、または null）",
  "nationality": "国籍（例: 日本、中国）",
  "available_from": "稼働可能日（YYYY-MM-DD形式、または null）",
  "preferred_location": "希望勤務地",
  "desired_unit_price_min": 希望単価下限（万円/月、整数またはnull）,
  "desired_unit_price_max": 希望単価上限（万円/月、整数またはnull）,
  "work_style": "希望勤務形態（remote/hybrid/office のいずれか、または null）",
  "self_introduction": "自己PR・経歴サマリー（300文字以内）",
  "skills": [
    {"name": "スキル名", "experience_years": 経験年数（数値またはnull）}
  ]
}

## 抽出ルール

### 性別（gender）の変換
- 「男」「男性」→ "male"
- 「女」「女性」→ "female"
- 「その他」→ "other"
- 不明・回答なし → null

### 最寄駅（nearest_station）
- 「地下鉄空港線　姪浜駅」→ "姪浜駅"
- 「東武伊勢崎線　羽生駅」→ "羽生駅"
- 「JR総武線　錦糸町駅」→ "錦糸町駅"
- 「八王子 駅」→ "八王子駅"
- 路線名・会社名を除き、駅名のみを抽出する

### 氏名（name）
- フルネームがなくイニシャル表記の場合はそのまま記録（例: S.N / A.S / SA）
- 「氏名」「イニシャル」ラベルの後の値を使う

### スキル（skills）
- 言語・FW・DB・インフラなどすべてのスキルを列挙
- 経験年数は「1年7ヶ月」→ 1.5、「0年9ヶ月」→ 0.75 のように小数で返す
- 重複は除く

### 稼働可能日（available_from）
- 「稼働」「稼働日」「稼働開始」ラベルの後の値を使う
- 「2026.4」→ "2026-04-01"、「即日」→ 今日の日付

JSONのみを返してください。説明文は不要です。
PROMPT;

        $response = Http::withHeaders([
            'anthropic-version' => '2023-06-01',
            'x-api-key'         => $this->apiKey,
            'content-type'      => 'application/json',
        ])->timeout(60)->post($this->apiUrl, [
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 2048,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        if ($response->failed()) {
            throw new \Exception('Claude API error: ' . $response->body());
        }

        $content = $response->json('content.0.text', '');
        return $this->parseResponse($content);
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
