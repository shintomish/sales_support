<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessCard;
use App\Models\Customer;
use App\Models\Contact;
use App\Http\Resources\BusinessCardResource;
use Illuminate\Http\Request;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Image;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;
use App\Services\ClaudeService;
use App\Services\BusinessCardRegistrationService;

class BusinessCardController extends Controller
{
    public function index()
    {
        $cards = BusinessCard::with(['customer', 'contact'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return BusinessCardResource::collection($cards);
    }

    public function store(Request $request)
    {
        \Log::info('BusinessCardController::store called');

        // ── Supabase Storage 対応: image_urls[] で受信 ──
        $request->validate([
            'image_urls'   => 'required|array|min:1|max:20',
            'image_urls.*' => 'required|url',
        ], [
            'image_urls.required'   => '画像URLは必須です',
            'image_urls.array'      => '画像URLは配列形式で送信してください',
            'image_urls.min'        => '少なくとも1枚の画像を選択してください',
            'image_urls.max'        => '一度にアップロードできる画像は20枚までです',
            'image_urls.*.required' => '画像URLは必須です',
            'image_urls.*.url'      => '正しいURL形式で送信してください',
        ]);

        try {
            $results = [];

            foreach ($request->input('image_urls') as $imageUrl) {

                // 1. Supabase Storage から画像を取得
                $imageContent = @file_get_contents($imageUrl);
                if ($imageContent === false) {
                    \Log::error('Failed to fetch image from Supabase: ' . $imageUrl);
                    continue;
                }

                // 2. Google Cloud Vision API で OCR 実行
                $credentialsPath = config('services.google_vision.credentials');
                putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $credentialsPath);
                $vision = new ImageAnnotatorClient();

                $feature      = (new Feature())->setType(Type::TEXT_DETECTION);
                $imageObj     = (new Image())->setContent($imageContent);
                $annotateReq  = (new AnnotateImageRequest())->setImage($imageObj)->setFeatures([$feature]);
                $batchRequest = (new BatchAnnotateImagesRequest())->setRequests([$annotateReq]);
                $response     = $vision->batchAnnotateImages($batchRequest);
                $annotations  = $response->getResponses()[0];

                if ($annotations->hasError()) {
                    \Log::error('OCR error: ' . $annotations->getError()->getMessage());
                    continue;
                }

                $texts = $annotations->getTextAnnotations();
                if (count($texts) === 0) continue;

                $ocrText = $texts[0]->getDescription();

                // 3. Claude API で情報抽出
                $claudeService = new ClaudeService();
                $extractedData = $claudeService->extractBusinessCardInfo($ocrText);

                // 4. 名刺データとして保存
                //    image_path には Supabase の公開 URL をそのまま格納
                $card = BusinessCard::create([
                    'user_id'      => $request->user()->id,
                    'ocr_text'     => $ocrText,
                    'company_name' => $extractedData['company_name'] ?? null,
                    'person_name'  => $extractedData['person_name']  ?? null,
                    'department'   => $extractedData['department']   ?? null,
                    'position'     => $extractedData['position']     ?? null,
                    'postal_code'  => $extractedData['postal_code']  ?? null,
                    'address'      => $extractedData['address']      ?? null,
                    'phone'        => $extractedData['phone']        ?? null,
                    'mobile'       => $extractedData['mobile']       ?? null,
                    'fax'          => $extractedData['fax']          ?? null,
                    'email'        => $extractedData['email']        ?? null,
                    'website'      => $extractedData['website']      ?? null,
                    'image_path'   => $imageUrl,   // ← Supabase の公開 URL
                    'status'       => 'processed',
                ]);

                // 5. 顧客・担当者を自動登録
                $registrationService = new BusinessCardRegistrationService();
                $result = $registrationService->register($card);
                $card->load(['customer', 'contact']);

                $results[] = [
                    'data' => new BusinessCardResource($card),
                    'registration' => [
                        'customer' => [
                            'id'     => $result['customer']->id,
                            'name'   => $result['customer']->company_name,
                            'is_new' => $result['is_new_customer'],
                        ],
                        'contact' => [
                            'id'   => $result['contact']->id,
                            'name' => $result['contact']->name,
                        ],
                    ],
                ];
            }

            return response()->json(['results' => $results], 201);

        } catch (\Exception $e) {
            \Log::error('Exception: ' . $e->getMessage());
            return response()->json([
                'message' => 'OCR処理に失敗しました',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        $card = BusinessCard::with(['customer', 'contact'])->findOrFail($id);
        return new BusinessCardResource($card);
    }

    public function update(Request $request, string $id)
    {
        $card = BusinessCard::findOrFail($id);

        $request->validate([
            'company_name' => 'nullable|string|max:255',
            'person_name'  => 'nullable|string|max:255',
            'department'   => 'nullable|string|max:100',
            'position'     => 'nullable|string|max:100',
            'postal_code'  => 'nullable|string|max:10',
            'address'      => 'nullable|string|max:500',
            'phone'        => ['nullable', 'string', 'max:20', 'regex:/^[\d\-\+\(\)\s]+$/'],
            'mobile'       => ['nullable', 'string', 'max:20', 'regex:/^[\d\-\+\(\)\s]+$/'],
            'fax'          => ['nullable', 'string', 'max:20', 'regex:/^[\d\-\+\(\)\s]+$/'],
            'email'        => 'nullable|email:rfc|max:255',
            'website'      => 'nullable|url|max:255',
            'status'       => 'nullable|in:processed,registered,pending',
        ], [
            'company_name.max' => '会社名は255文字以内で入力してください',
            'person_name.max'  => '氏名は255文字以内で入力してください',
            'department.max'   => '部署は100文字以内で入力してください',
            'position.max'     => '役職は100文字以内で入力してください',
            'postal_code.max'  => '郵便番号は10文字以内で入力してください',
            'address.max'      => '住所は500文字以内で入力してください',
            'phone.regex'      => '電話番号の形式が正しくありません（例: 03-1234-5678）',
            'phone.max'        => '電話番号は20文字以内で入力してください',
            'mobile.regex'     => '携帯番号の形式が正しくありません（例: 090-1234-5678）',
            'mobile.max'       => '携帯番号は20文字以内で入力してください',
            'fax.regex'        => 'FAX番号の形式が正しくありません',
            'fax.max'          => 'FAX番号は20文字以内で入力してください',
            'email.email'      => 'メールアドレスの形式が正しくありません',
            'email.max'        => 'メールアドレスは255文字以内で入力してください',
            'website.url'      => 'WebサイトのURLの形式が正しくありません（例: https://example.com）',
            'website.max'      => 'WebサイトのURLは255文字以内で入力してください',
            'status.in'        => 'ステータスの値が正しくありません',
        ]);

        $card->update($request->only([
            'company_name', 'person_name', 'department', 'position',
            'postal_code', 'address', 'phone', 'mobile', 'fax',
            'email', 'website', 'status',
        ]));

        return new BusinessCardResource($card);
    }

    public function destroy(string $id)
    {
        $card = BusinessCard::findOrFail($id);
        // image_path が Supabase URL の場合はローカル削除不要
        // （Supabase 側の削除は将来的に対応）
        if ($card->image_path && !str_starts_with($card->image_path, 'http')) {
            \Storage::disk('public')->delete($card->image_path);
        }
        $card->delete();
        return response()->json(null, 204);
    }
}
