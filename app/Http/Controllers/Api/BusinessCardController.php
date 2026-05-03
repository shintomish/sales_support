<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessCard;
use OpenApi\Attributes as OA;
use App\Http\Resources\BusinessCardResource;
use App\Services\SupabaseStorageService;
use App\Services\ClaudeService;
use App\Services\BusinessCardRegistrationService;
use App\Services\GoogleCredentialService;
use App\Services\ImageOrientationService;
use App\Services\MultiCardSplitterService;
use App\Services\MultiCardDetectionService;
use Illuminate\Http\Request;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Image;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;

class BusinessCardController extends Controller
{
    #[OA\Get(
        path: '/api/v1/cards',
        summary: '名刺一覧取得',
        security: [['bearerAuth' => []]],
        tags: ['BusinessCards'],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function index(\Illuminate\Http\Request $request)
    {
        $userFilter = $this->resolveUserFilter($request);

        $cards = BusinessCard::with(['customer', 'contact'])
            ->when($userFilter, fn($q, $id) => $q->where('user_id', $id))
            ->orderBy(...$this->resolveSort($request, [
                'created_at'   => 'created_at',
                'company_name' => 'company_name',
                'person_name'  => 'person_name',
                'position'     => 'position',
                'status'       => 'status',
            ], 'created_at', 'desc'))
            ->paginate(50);

        return BusinessCardResource::collection($cards);
    }

    #[OA\Post(
        path: '/api/v1/cards',
        summary: '名刺画像OCR解析・登録（Google Vision + Claude API）',
        security: [['bearerAuth' => []]],
        tags: ['BusinessCards'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'images[]', type: 'array', items: new OA\Items(type: 'string', format: 'binary'), description: 'JPEG/PNG画像（最大20枚・各10MB）'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: '登録成功（顧客・担当者も自動登録）'),
            new OA\Response(response: 422, description: 'バリデーションエラー'),
            new OA\Response(response: 500, description: 'OCR処理失敗'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function store(Request $request)
    {
        \Log::info('BusinessCardController::store called');

        // multipart/form-data で画像受信
        try {
        $request->validate([
            'images'   => 'required|array|min:1|max:50',
            'images.*' => 'required|image|mimes:jpeg,png,jpg|max:10240',
        ], [
            'images.required'   => '画像ファイルは必須です',
            'images.array'      => '画像は配列形式で送信してください',
            'images.min'        => '少なくとも1枚の画像を選択してください',
            'images.max'        => '一度にアップロードできる画像は50枚までです（並列リクエスト推奨）',
            'images.*.required' => '画像ファイルは必須です',
            'images.*.image'    => '画像ファイルのみアップロードできます',
            'images.*.mimes'    => '対応形式はJPEG・PNG・JPGのみです',
            'images.*.max'      => '各画像は10MB以内にしてください',
        ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('VALIDATION ERROR: ' . json_encode($e->errors()));
            throw $e;
        }

        $results  = [];
        $supabase = new SupabaseStorageService();
        $orient   = new ImageOrientationService();

        foreach ($request->file('images') as $imageFile) {
            $originalName = $imageFile->getClientOriginalName();
            try {
                // 0. 画像の向きを正規化 (EXIF + 縦長は90度回転)
                $rawBinary  = file_get_contents($imageFile->getRealPath());
                $imageContent = $orient->normalize($rawBinary);

                // 1. Google Cloud Vision API で OCR 実行
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
                    throw new \RuntimeException('OCR エラー: ' . $annotations->getError()->getMessage());
                }

                $texts = $annotations->getTextAnnotations();
                if (count($texts) === 0) {
                    throw new \RuntimeException('テキストを検出できませんでした');
                }

                $ocrText = $texts[0]->getDescription();

                // 2. Claude API で情報抽出
                $claudeService = new ClaudeService();
                $extractedData = $claudeService->extractBusinessCardInfo($ocrText);

                // 3. 氏名をファイル名に使ってSupabase Storageにアップロード
                //    回転後のバイナリを直接アップロード (元ファイルは縦のままなので)
                $personName = $extractedData['person_name'] ?? null;
                $rawName    = $personName ? str_replace([' ', '　'], '', $personName)
                                          : pathinfo($originalName, PATHINFO_FILENAME);
                $safeName   = preg_replace('/[^\w\-\.]/u', '_', $rawName);
                $safeName   = preg_replace('/[^\x00-\x7F]/u', '', $safeName);
                $safeName   = preg_replace('/_+/', '_', trim($safeName, '_'));
                if ($safeName === '') $safeName = substr(md5($rawName), 0, 8);
                $filename   = 'cards/' . $safeName . '_' . now()->format('Ymd_His_') . substr(md5($imageContent), 0, 6) . '.jpg';
                $imageUrl   = $supabase->uploadBinary($imageContent, $filename, 'image/jpeg');

                // 4. 既存 BusinessCard を検索 (同一会社+同一氏名・異体字許容)
                //    あれば上書き更新、なければ新規作成
                $registrationService = new BusinessCardRegistrationService();
                $existingCard = $registrationService->findExistingCard(
                    $extractedData['company_name'] ?? null,
                    $extractedData['person_name']  ?? null,
                );

                $payload = [
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
                ];

                if ($existingCard) {
                    \Log::info("BusinessCard を上書き更新: id={$existingCard->id} {$existingCard->company_name}/{$existingCard->person_name}");
                    // 既存画像は Supabase に残るが古い参照は失う（必要なら削除）
                    if ($existingCard->image_path && str_starts_with($existingCard->image_path, 'http')
                        && $existingCard->image_path !== $imageUrl) {
                        try { $supabase->delete($existingCard->image_path); }
                        catch (\Throwable $e) { \Log::warning('旧画像削除失敗: ' . $e->getMessage()); }
                    }
                    $existingCard->update($payload);
                    $card = $existingCard;
                } else {
                    $card = BusinessCard::create($payload);
                }

                // 5. 顧客・担当者を自動登録 (既存があれば更新)
                $regResult = $registrationService->register($card);
                $card->load(['customer', 'contact']);

                $results[] = [
                    'success'      => true,
                    'source_name'  => $originalName,
                    'data'         => new BusinessCardResource($card),
                    'registration' => [
                        'customer' => [
                            'id'     => $regResult['customer']->id,
                            'name'   => $regResult['customer']->company_name,
                            'is_new' => $regResult['is_new_customer'],
                        ],
                        'contact' => [
                            'id'   => $regResult['contact']->id,
                            'name' => $regResult['contact']->name,
                        ],
                    ],
                ];
            } catch (\Throwable $e) {
                // 1枚の失敗で全体を止めない
                \Log::warning("BusinessCard OCR failed for {$originalName}: " . $e->getMessage());
                $results[] = [
                    'success'     => false,
                    'source_name' => $originalName,
                    'error'       => $e->getMessage(),
                ];
            }
        }

        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $statusCode   = $successCount > 0 ? 201 : 422;

        return response()->json([
            'results'       => $results,
            'success_count' => $successCount,
            'failure_count' => count($results) - $successCount,
        ], $statusCode);
    }

    #[OA\Get(
        path: '/api/v1/cards/{id}',
        summary: '名刺詳細取得',
        security: [['bearerAuth' => []]],
        tags: ['BusinessCards'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '名刺ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 404, description: '見つかりません'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function show(string $id)
    {
        $card = BusinessCard::with(['customer', 'contact'])->findOrFail($id);
        return new BusinessCardResource($card);
    }

    #[OA\Put(
        path: '/api/v1/cards/{id}',
        summary: '名刺情報更新',
        security: [['bearerAuth' => []]],
        tags: ['BusinessCards'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '名刺ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: '更新成功'),
            new OA\Response(response: 422, description: 'バリデーションエラー'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
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

    #[OA\Delete(
        path: '/api/v1/cards/{id}',
        summary: '名刺削除（Supabase Storageからも削除）',
        security: [['bearerAuth' => []]],
        tags: ['BusinessCards'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '名刺ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: '削除成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    /**
     * POST /api/v1/cards/detect
     *
     * 1画像に複数名刺が並んでいる場合に、向き正規化 + 推定分割した
     * サブ画像を base64 で返す。フロントは各サブ画像を /cards に送って登録する。
     */
    public function detect(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg|max:20480',
        ], [
            'image.required' => '画像ファイルは必須です',
            'image.image'    => '画像ファイルのみアップロードできます',
            'image.mimes'    => '対応形式はJPEG・PNG・JPGのみです',
            'image.max'      => '画像は20MB以内にしてください',
        ]);

        try {
            $orient    = new ImageOrientationService();
            $detector  = app(MultiCardDetectionService::class);
            $splitter  = new MultiCardSplitterService();

            $raw      = file_get_contents($request->file('image')->getRealPath());
            $oriented = $orient->normalize($raw);

            // 1. Vision API で領域検出 (グリッド配置にも対応)
            $img = @imagecreatefromstring($oriented);
            $subImages = [];
            $method    = 'fallback';

            if ($img !== false) {
                $w = imagesx($img); $h = imagesy($img);
                $rects = $detector->detect($oriented, $w, $h);
                if (count($rects) >= 2) {
                    foreach ($rects as $r) {
                        $cell = imagecreatetruecolor($r['w'], $r['h']);
                        imagecopy($cell, $img, 0, 0, $r['x'], $r['y'], $r['w'], $r['h']);
                        ob_start();
                        imagejpeg($cell, null, 90);
                        $bin = ob_get_clean();
                        imagedestroy($cell);
                        if ($bin !== false) $subImages[] = $bin;
                    }
                    $method = 'vision';
                }
                imagedestroy($img);
            }

            // 2. Vision で分割できなければ既存ヒューリスティック (横/縦一列向け)
            if (empty($subImages)) {
                $subImages = $splitter->split($oriented);
                $method    = 'aspect';
            }

            $cards = array_map(
                fn ($bin) => 'data:image/jpeg;base64,' . base64_encode($bin),
                $subImages
            );

            return response()->json([
                'count'  => count($cards),
                'method' => $method,
                'cards'  => $cards,
            ]);
        } catch (\Throwable $e) {
            \Log::error('cards/detect failed: ' . $e->getMessage());
            return response()->json(['message' => '名刺検出に失敗しました', 'error' => $e->getMessage()], 500);
        }
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
