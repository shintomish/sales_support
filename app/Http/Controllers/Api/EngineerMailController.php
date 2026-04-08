<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailAttachment;
use App\Models\EngineerMailSource;
use App\Models\GmailToken;
use App\Services\EngineerMailScoringService;
use App\Services\GmailService;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EngineerMailController extends Controller
{
    public function __construct(
        private EngineerMailScoringService $scoringService,
    ) {}

    // 一覧
    public function index(Request $request)
    {
        $perPage  = $request->integer('per_page', 30);
        $status   = $request->string('status');
        $scoreMin = $request->integer('score_min', 0);
        $scoreMax = $request->integer('score_max', 100);
        $search   = $request->string('search');

        $query = EngineerMailSource::with(['email:id,subject,from_name,from_address,received_at'])
            ->whereBetween('score', [$scoreMin, $scoreMax])
            ->orderByDesc('received_at');

        if ($status) {
            $query->where('status', $status);
        } else {
            $query->whereNotIn('status', ['excluded']);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('nearest_station', 'like', "%{$search}%")
                  ->orWhere('affiliation_type', 'like', "%{$search}%")
                  ->orWhereHas('email', fn($eq) =>
                      $eq->where('subject', 'like', "%{$search}%")
                         ->orWhere('from_name', 'like', "%{$search}%")
                  );
            });
        }

        return response()->json($query->paginate($perPage));
    }

    // 詳細（元メール・添付含む）
    public function show(int $id)
    {
        $ems = EngineerMailSource::with([
            'email.attachments',
        ])->findOrFail($id);

        return response()->json($ems);
    }

    // 抽出情報の手動修正
    public function update(Request $request, int $id)
    {
        $ems = EngineerMailSource::findOrFail($id);

        $v = $request->validate([
            'name'             => 'nullable|string|max:100',
            'affiliation_type' => 'nullable|string|max:50',
            'available_from'   => 'nullable|string|max:50',
            'nearest_station'  => 'nullable|string|max:100',
            'skills'           => 'nullable|array',
            'skills.*'         => 'string|max:100',
        ]);

        $ems->update($v);

        return response()->json($ems->fresh());
    }

    // ステータス変更
    public function updateStatus(Request $request, int $id)
    {
        $ems = EngineerMailSource::findOrFail($id);

        $v = $request->validate([
            'status' => 'required|in:review,new,registered,proposing,working,excluded',
        ]);

        $ems->update($v);

        return response()->json($ems->fresh());
    }

    // 添付ファイルダウンロード
    public function downloadAttachment(int $id, int $attachmentId): Response
    {
        $ems = EngineerMailSource::with('email')->findOrFail($id);
        $att = EmailAttachment::where('id', $attachmentId)
            ->where('email_id', $ems->email_id)
            ->firstOrFail();

        $filename = $att->filename ?: 'attachment';
        $mimeType = $att->mime_type ?: 'application/octet-stream';

        // Storage保存済み → Supabaseからservice_roleキーで取得してストリーム
        if ($att->storage_path) {
            $supabaseUrl = config('services.supabase.url');
            $serviceKey  = config('services.supabase.service_role_key');
            $bucket      = config('services.supabase.bucket');

            // storage_pathからbucket以降のパスを抽出
            $pattern = "/storage\/v1\/object\/public\/{$bucket}\//";
            $path    = preg_replace($pattern, '', parse_url($att->storage_path, PHP_URL_PATH));

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$serviceKey}",
            ])->get("{$supabaseUrl}/storage/v1/object/{$bucket}/{$path}");

            if ($response->successful()) {
                return response($response->body(), 200, [
                    'Content-Type'        => $mimeType,
                    'Content-Disposition' => 'attachment; filename="' . rawurlencode($filename) . '"',
                ]);
            }
        }

        // storage_pathなし or Storage取得失敗 → Gmail APIから取得
        $gmailToken = GmailToken::where('tenant_id', $ems->email->tenant_id)->first();
        if (!$gmailToken || !$ems->email->gmail_message_id || !$att->gmail_attachment_id) {
            abort(404, '添付ファイルを取得できませんでした');
        }

        $gmailService = app(GmailService::class);
        $rawData      = $gmailService->fetchAttachmentData(
            $gmailToken,
            $ems->email->gmail_message_id,
            $att->gmail_attachment_id
        );

        if (!$rawData) {
            abort(404, '添付ファイルを取得できませんでした');
        }

        $binary = base64_decode(str_replace(['-', '_'], ['+', '/'], $rawData));

        // Storageに保存（次回以降のために）
        if (empty($att->storage_path)) {
            try {
                $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $base     = preg_replace('/[^\w\-\.]/u', '_', pathinfo($filename, PATHINFO_FILENAME));
                $base     = preg_replace('/[^\x00-\x7F]/u', '', $base) ?: substr(md5($filename), 0, 8);
                $path     = "attachments/{$ems->email->tenant_id}/{$ems->email_id}/{$base}.{$ext}";
                $storage  = app(SupabaseStorageService::class);
                $url      = $storage->uploadBinary($binary, $path, $mimeType);
                $att->update(['storage_path' => $url]);
            } catch (\Throwable $e) {
                Log::debug("[EngineerMailController] 添付Storage保存失敗 att_id={$att->id}: " . $e->getMessage());
            }
        }

        return response($binary, 200, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($filename) . '"',
        ]);
    }

    // 未処理メールを手動で一括スコアリング（1回50件ずつ処理）
    public function scoreAll(): JsonResponse
    {
        set_time_limit(120);
        $batchSize = 50;
        $count     = $this->scoringService->scorePending($batchSize);
        $remaining = $this->scoringService->pendingCount();

        return response()->json([
            'message'   => "{$count}件をスコアリングしました",
            'count'     => $count,
            'remaining' => $remaining,
        ]);
    }

    // 既存レコードを全件再スコアリング＋再抽出
    public function rescoreAll(): JsonResponse
    {
        $count = $this->scoringService->rescoreAll();
        return response()->json([
            'message' => "{$count}件を再スコアリングしました",
            'count'   => $count,
        ]);
    }
}
