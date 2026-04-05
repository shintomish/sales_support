<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessCard;
use App\Http\Resources\BusinessCardResource;
use App\Services\SupabaseStorageService;
use App\Services\ClaudeService;
use App\Services\BusinessCardRegistrationService;
use App\Services\GoogleCredentialService;
use Illuminate\Http\Request;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Image;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;

class BusinessCardController extends Controller
{
    public function index(\Illuminate\Http\Request $request)
    {
        $userFilter = $this->resolveUserFilter($request);

        $cards = BusinessCard::with(['customer', 'contact'])
            ->when($userFilter, fn($q, $id) => $q->where('user_id', $id))
            ->orderBy(...$this->resolveSort($request, [
                'created_at'   => 'created_at',
                'company_name' => 'company_name',
                'person_name'  => 'person_name',
            ], 'created_at', 'desc'))
            ->paginate(20);

        return BusinessCardResource::collection($cards);
    }

    public function store(Request $request)
    {
        \Log::info('BusinessCardController::store called');

        // multipart/form-data で画像受信
        try {
        $request->validate([
            'images'   => 'required|array|min:1|max:20',
            'images.*' => 'required|image|mimes:jpeg,png,jpg|max:10240',
        ], [
            'images.required'   => '画像ファイルは必須です',
            'images.array'      => '画像は配列形式で送信してください',
            'images.min'        => '少なくとも1枚の画像を選択してください',
            'images.max'        => '一度にアップロードできる画像は20枚までです',
            'images.*.required' => '画像ファイルは必須です',
            'images.*.image'    => '画像ファイルのみアップロードできます',
            'images.*.mimes'    => '対応形式はJPEG・PNG・JPGのみです',
            'images.*.max'      => '各画像は10MB以内にしてください',
        ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('VALIDATION ERROR: ' . json_encode($e->errors()));
            throw $e;
        }

        try {
            $results  = [];
            $supabase = new SupabaseStorageService();

            foreach ($request->file('images') as $imageFile) {

                // 1. LaravelサーバーからSupabase Storageにアップロード
                $imageUrl     = $supabase->upload($imageFile, 'cards');
                $imageContent = file_get_contents($imageFile->getRealPath());

                // 2. Google Cloud Vision API で OCR 実行
                $credentialsJson = app(GoogleCredentialService::class)->getCredentials();
                $vision = new ImageAnnotatorClient([
                    'credentials' => $credentialsJson,
                ]);

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

                // 4. 名刺データとして保存（image_path に Supabase 公開 URL を格納）
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
                    'image_path'   => $imageUrl,
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

        // Supabase Storage からも削除
        if ($card->image_path && str_starts_with($card->image_path, 'http')) {
            try {
                (new SupabaseStorageService())->delete($card->image_path);
            } catch (\Exception $e) {
                \Log::warning('Supabase delete failed: ' . $e->getMessage());
            }
        } elseif ($card->image_path) {
            \Storage::disk('public')->delete($card->image_path);
        }

        $card->delete();
        return response()->json(null, 204);
    }
}
