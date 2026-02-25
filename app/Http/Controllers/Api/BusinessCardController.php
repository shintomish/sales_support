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
use Google\Cloud\Vision\V1\AnnotateImageRequest;  // ← この行を追加
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;  // ← この行を追加
use App\Services\ClaudeService;

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
        \Log::info('Request data:', $request->all());
        \Log::info('Has file:', ['has' => $request->hasFile('image')]);
        \Log::info('File info:', [
            'name' => $request->file('image')?->getClientOriginalName(),
            'size' => $request->file('image')?->getSize(),
            'mime' => $request->file('image')?->getMimeType(),
            'extension' => $request->file('image')?->getClientOriginalExtension(),
        ]);

        try {
            \Log::info('Starting validation...');

            $validator = \Validator::make($request->all(), [
                'image' => 'required|image|mimes:jpeg,png,jpg|max:10240',
            ]);

            if ($validator->fails()) {
                \Log::error('Validation failed:', $validator->errors()->toArray());
                return response()->json([
                    'message' => 'バリデーションエラー',
                    'errors' => $validator->errors()
                ], 422);
            }

            \Log::info('Validation passed');

            // 1. 画像を保存
            \Log::info('Saving image...');
            $imagePath = $request->file('image')->store('business_cards', 'public');
            \Log::info('Image saved: ' . $imagePath);
            $fullPath = storage_path('app/public/' . $imagePath);

            // 2. Google Cloud Vision APIでOCR実行
            // 2. Google Cloud Vision APIでOCR実行
            \Log::info('Starting OCR...');
            putenv('GOOGLE_APPLICATION_CREDENTIALS=/var/www/storage/credentials/google-vision.json');
            $vision = new ImageAnnotatorClient();

            $imageContent = file_get_contents($fullPath);

            $feature = (new Feature())->setType(Type::TEXT_DETECTION);
            $imageObj = (new Image())->setContent($imageContent);
            $annotateRequest = (new AnnotateImageRequest())  // ← 変数名を変更
                ->setImage($imageObj)
                ->setFeatures([$feature]);

            $batchRequest = (new BatchAnnotateImagesRequest())
                ->setRequests([$annotateRequest]);  // ← ここも変更

            $response = $vision->batchAnnotateImages($batchRequest);
            $annotations = $response->getResponses()[0];

            \Log::info('OCR response received');

            if ($annotations->hasError()) {
                \Log::error('OCR error: ' . $annotations->getError()->getMessage());
                return response()->json([
                    'message' => 'OCR処理でエラーが発生しました'
                ], 500);
            }

            $texts = $annotations->getTextAnnotations();

            if (count($texts) === 0) {
                \Log::warning('No text detected');
                return response()->json([
                    'message' => 'テキストが検出されませんでした'
                ], 400);
            }

            $ocrText = $texts[0]->getDescription();
            \Log::info('OCR text extracted: ' . substr($ocrText, 0, 100));

            // 3. Claude APIで情報抽出・構造化
            \Log::info('Starting Claude API...');
            $claudeService = new ClaudeService();
            $extractedData = $claudeService->extractBusinessCardInfo($ocrText);
            \Log::info('Claude API completed', $extractedData);

            // 4. 名刺データとして保存
            \Log::info('Saving to database...');
            $card = BusinessCard::create([
                'user_id' => $request->user()->id,
                'ocr_text' => $ocrText,
                'company_name' => $extractedData['company_name'] ?? null,
                'person_name' => $extractedData['person_name'] ?? null,
                'department' => $extractedData['department'] ?? null,
                'position' => $extractedData['position'] ?? null,
                'postal_code' => $extractedData['postal_code'] ?? null,
                'address' => $extractedData['address'] ?? null,
                'phone' => $extractedData['phone'] ?? null,
                'mobile' => $extractedData['mobile'] ?? null,
                'fax' => $extractedData['fax'] ?? null,
                'email' => $extractedData['email'] ?? null,
                'website' => $extractedData['website'] ?? null,
                'image_path' => $imagePath,
                'status' => 'processed',
            ]);
            \Log::info('Database saved, ID: ' . $card->id);

            return new BusinessCardResource($card);

        } catch (\Exception $e) {
            \Log::error('Exception occurred: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'message' => 'OCR処理に失敗しました',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function show(BusinessCard $businessCard)
    {
        return new BusinessCardResource($businessCard->load(['customer', 'contact']));
    }

    public function destroy(BusinessCard $businessCard)
    {
        // 画像ファイルも削除
        if ($businessCard->image_path) {
            \Storage::disk('public')->delete($businessCard->image_path);
        }

        $businessCard->delete();
        return response()->json(null, 204);
    }
}
