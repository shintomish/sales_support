<?php

namespace App\Http\Controllers;

use App\Models\BusinessCard;
use Illuminate\Http\Request;

class BusinessCardController extends Controller
{
    /**
     * 名刺一覧
     */
    public function index()
    {
        $cards = BusinessCard::with(['customer', 'contact', 'user'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('business-cards.index', compact('cards'));
    }

    /**
     * 名刺詳細
     */
    public function show(BusinessCard $businessCard)
    {
        $businessCard->load(['customer', 'contact', 'user']);

        return view('business-cards.show', compact('businessCard'));
    }

    /**
     * 編集画面
     */
    public function edit(BusinessCard $businessCard)
    {
        $businessCard->load(['customer', 'contact']);

        return view('business-cards.edit', compact('businessCard'));
    }

    /**
     * 更新処理
     */
    public function update(Request $request, BusinessCard $businessCard)
    {
        $validated = $request->validate([
            'company_name' => 'nullable|string|max:255',
            'person_name' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:10',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'fax' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:255',
        ]);

        $businessCard->update($validated);

        return redirect()->route('business-cards.show', $businessCard)
            ->with('success', '名刺情報を更新しました');
    }

    /**
     * 削除処理
     */
    public function destroy(BusinessCard $businessCard)
    {
        // 画像ファイルも削除
        if ($businessCard->image_path) {
            \Storage::disk('public')->delete($businessCard->image_path);
        }

        $businessCard->delete();

        return redirect()->route('business-cards.index')
            ->with('success', '名刺を削除しました');
    }

    /**
     * アップロード画面
     */
    public function create()
    {
        return view('business-cards.create');
    }

    /**
     * アップロード処理
     */
    public function store(Request $request)
    {
        \Log::info('=== store() called ===');
        \Log::info('files: ', ['has_images' => $request->hasFile('images')]);

        $request->validate([
            'images' => 'required',
            'images.*' => 'required|image|mimes:jpeg,png,jpg|max:10240',
        ], [
            'images.required' => '名刺画像を選択してください',
            'images.*.image' => '画像ファイルを選択してください',
            'images.*.mimes' => 'JPEG、PNG、JPG形式の画像を選択してください',
            'images.*.max' => '画像サイズは10MB以下にしてください',
        ]);

        $uploadedCount = 0;
        $errors = [];

        foreach ($request->file('images') as $image) {
            try {
                // Api\BusinessCardControllerの処理を直接呼び出す
                $apiController = new \App\Http\Controllers\Api\BusinessCardController();
                $singleRequest = Request::create('/api/v1/cards', 'POST');
                $singleRequest->setUserResolver(fn() => $request->user());
                $singleRequest->files->set('image', $image);

                $response = $apiController->store($singleRequest);
                $data = json_decode($response->getContent(), true);

                if ($response->getStatusCode() === 201) {
                    $uploadedCount++;
                } else {
                    $errors[] = $image->getClientOriginalName() . ': ' . ($data['message'] ?? '失敗');
                }
            } catch (\Exception $e) {
                $errors[] = $image->getClientOriginalName() . ': ' . $e->getMessage();
            }
        }

        if (count($errors) > 0) {
            return redirect()->route('business-cards.index')
                ->with('warning', "{$uploadedCount}件成功、" . count($errors) . "件失敗")
                ->with('errors', $errors);
        }

        \Log::info('=== store() end ===');

        return redirect()->route('business-cards.index')
            ->with('success', "{$uploadedCount}件の名刺をアップロードしました");
    }

}
