<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\GmailToken;
use App\Services\GmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
class EmailController extends Controller
{
    public function __construct(
        private GmailService $gmailService,
    ) {}

    // メール一覧
    public function index(Request $request)
    {
        $perPage  = $request->integer('per_page', 30);
        $search   = $request->string('search');
        $unread   = $request->boolean('unread');
        $category = $request->string('category');   // engineer / project / ''
        $query = Email::query()
            ->orderBy('received_at', 'desc');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('from_address', 'like', "%{$search}%")
                  ->orWhere('from_name', 'like', "%{$search}%")
                  ->orWhere('body_text', 'like', "%{$search}%");
            });
        }
        if ($unread) {
            $query->where('is_read', false);
        }
        if ($category) {
            $query->where('category', $category);
        }
        return response()->json(
            $query->withCount('attachments')->paginate($perPage)
        );
    }

    // メール詳細（自動既読）
    public function show(int $id)
    {
        $email = Email::findOrFail($id);
        // 既読にする
        if (!$email->is_read) {
            $token = GmailToken::where('tenant_id', auth()->user()->tenant_id)->first();
            if ($token) {
                $this->gmailService->markAsRead($token, $email->gmail_message_id);
            }
            $email->update(['is_read' => true]);
        }
        return response()->json($email->load(['contact', 'deal', 'customer', 'attachments']));
    }

    // Gmail から最新メールを同期
    public function sync()
    {
        $user  = auth()->user();
        $token = GmailToken::where('tenant_id', $user->tenant_id)->first();
        if (!$token) {
            return response()->json(['message' => 'Gmail未接続です'], 422);
        }
        try {
            $count = $this->gmailService->fetchAndStoreEmails($token);

            return response()->json([
                'message' => "{$count}件の新着メールを取得しました",
                'count'   => $count,
            ]);
        } catch (\Exception $e) {
            Log::error('Email sync failed: ' . $e->getMessage());
            if (str_contains($e->getMessage(), 'invalid_grant')) {
                return response()->json([
                    'message'       => 'Gmailトークンが失効しました。再接続してください。',
                    'token_expired' => true,
                ], 401);
            }
            return response()->json(['message' => 'メール同期に失敗しました'], 500);
        }
    }

    // 担当者・商談への紐付け
    public function link(Request $request, int $id)
    {
        $request->validate([
            'contact_id'  => 'nullable|exists:contacts,id',
            'deal_id'     => 'nullable|exists:deals,id',
            'customer_id' => 'nullable|exists:customers,id',
        ]);
        $email = Email::findOrFail($id);
        $email->update($request->only(['contact_id', 'deal_id', 'customer_id']));
        return response()->json($email->load(['contact', 'deal', 'customer']));
    }

    // 未読件数
    public function unreadCount()
    {
        $count = Email::where('is_read', false)->count();
        return response()->json(['count' => $count]);
    }

    // ── 添付ファイルダウンロード ──────────────────────────────

    public function downloadAttachment(int $emailId, int $attachmentId)
    {
        $email      = Email::findOrFail($emailId);
        $attachment = $email->attachments()->findOrFail($attachmentId);

        $token = GmailToken::where('tenant_id', auth()->user()->tenant_id)->first();
        if (!$token) {
            return response()->json(['message' => 'Gmail未接続です'], 422);
        }

        try {
            $data = $this->gmailService->fetchAttachmentData(
                $token,
                $email->gmail_message_id,
                $attachment->gmail_attachment_id
            );

            return response($data)
                ->header('Content-Type', $attachment->mime_type ?? 'application/octet-stream')
                ->header('Content-Disposition', 'attachment; filename="' . rawurlencode($attachment->filename) . '"')
                ->header('Content-Length', strlen($data));

        } catch (\Exception $e) {
            Log::error("Attachment download failed: {$e->getMessage()}");
            return response()->json(['message' => 'ダウンロードに失敗しました'], 500);
        }
    }

    // 全件既読
    public function markAllRead()
    {
        $tenantId = auth()->user()->tenant_id;
        $updated = Email::where('tenant_id', $tenantId)
            ->where('is_read', false)
            ->update(['is_read' => true]);
        return response()->json([
            'message' => "{$updated}件を既読にしました",
            'count'   => $updated,
        ]);
    }
}
