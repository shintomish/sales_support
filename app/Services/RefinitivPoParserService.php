<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Smalot\PdfParser\Parser as SmalotPdfParser;

/**
 * Refinitiv (LSEG) が SAP Business Network 経由で送ってくる注文書 PDF を構造化パースする
 *
 * フロー:
 *   1. smalot/pdfparser で生テキストを抽出
 *   2. Claude Sonnet 4 に渡して JSON 化（フォーマット揺れ吸収）
 *   3. 後段の請求書発行で order_number / vendor_metadata に流用
 *
 * 抽出フィールドは PDF ② (S2604-RJ014) の「その他の情報」欄と完全に一致させている。
 */
class RefinitivPoParserService
{
    private string $apiKey;
    private string $apiUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct()
    {
        $this->apiKey = (string) config('services.anthropic.api_key');
    }

    /**
     * @param  string $pdfPath  ローカルファイルパス（一時保存済み想定）
     * @return array{
     *   po_number: ?string,
     *   total_amount: ?int,
     *   description: ?string,
     *   requested_delivery_date: ?string,
     *   amount_based_receipt: ?string,
     *   purchase_request_line: ?string,
     *   requester: ?string,
     *   request_number: ?string,
     *   plant_id: ?string,
     *   plant_name: ?string,
     *   tr_plant_id: ?string,
     *   ship_to_address_name: ?string,
     *   classification_domain: ?string,
     *   classification_code: ?string,
     *   raw_text: string,
     * }
     */
    public function parse(string $pdfPath): array
    {
        if (!is_file($pdfPath)) {
            throw new RuntimeException('PDF ファイルが見つかりません: ' . $pdfPath);
        }

        $rawText = (new SmalotPdfParser())->parseFile($pdfPath)->getText();
        $extracted = $this->extractWithClaude($rawText);

        return array_merge($extracted, ['raw_text' => $rawText]);
    }

    private function extractWithClaude(string $rawText): array
    {
        $prompt = $this->buildPrompt($rawText);

        $response = Http::withHeaders([
            'anthropic-version' => '2023-06-01',
            'x-api-key'         => $this->apiKey,
            'content-type'      => 'application/json',
        ])->timeout(60)->post($this->apiUrl, [
            'model'      => config('services.anthropic.model'),
            'max_tokens' => 1024,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Claude API error: ' . $response->body());
        }

        $body = $response->json();
        $content = $body['content'][0]['text'] ?? '';
        return $this->decodeJson($content);
    }

    private function buildPrompt(string $text): string
    {
        // 余分な空白を圧縮して送信トークンを節約
        $compact = preg_replace('/\s{2,}/u', ' ', $text);

        return <<<PROMPT
以下は Refinitiv (LSEG) が SAP Business Network 経由で発行した注文書 PDF から抽出した生テキストです。
このテキストから注文書の必要項目を抽出し、JSON のみを返してください。説明文は不要です。
見つからない項目は null としてください。

# 抽出ルール
- po_number: ヘッダの「注文書 (新規)」直下にある 10 桁前後の数字（例: 8000089588）
- total_amount: 「金額」または「小計」の JPY 金額（円、整数のみ。カンマや通貨記号は除く）
- description: 明細行の品名行（例: "Aizen - SMBC Nikko - Market data support for Jefferies JV"）
- requested_delivery_date: 希望納入日 (YYYY-MM-DD)
- amount_based_receipt: 「金額による受入」の値（Yes/No）
- purchase_request_line: 「購入申請明細番号」
- requester: 「申請者」氏名
- request_number: 「申請番号」（例: PR549933）
- plant_id: 「Plant.ID」
- plant_name: 「Plant.Name」
- tr_plant_id: 「TR_PlantID」
- ship_to_address_name: 「ShipToAddressName」または「Ship ToAddressName」
- classification_domain: 「分類ドメイン」
- classification_code: 「分類コード」

# 入力テキスト
{$compact}

# 出力 JSON
{
  "po_number": "...",
  "total_amount": 1234567,
  "description": "...",
  "requested_delivery_date": "2026-06-30",
  "amount_based_receipt": "Yes",
  "purchase_request_line": "1",
  "requester": "...",
  "request_number": "PR...",
  "plant_id": "4054",
  "plant_name": "Refinitiv Japan K.K.",
  "tr_plant_id": "4054",
  "ship_to_address_name": "Refinitiv Japan K.K.",
  "classification_domain": "unspsc",
  "classification_code": "80101507"
}
PROMPT;
    }

    private function decodeJson(string $content): array
    {
        // Claude が ```json ... ``` で囲んで返すケースを除去
        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```$/i', '', $content);

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Claude 応答を JSON として解釈できませんでした: ' . $content);
        }

        $keys = [
            'po_number', 'total_amount', 'description', 'requested_delivery_date',
            'amount_based_receipt', 'purchase_request_line', 'requester', 'request_number',
            'plant_id', 'plant_name', 'tr_plant_id', 'ship_to_address_name',
            'classification_domain', 'classification_code',
        ];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $decoded[$k] ?? null;
        }
        return $out;
    }
}
