<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\GmailToken;
use App\Services\GmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;
class EmailController extends Controller
{
    public function __construct(
        private GmailService $gmailService,
    ) {}

    #[OA\Get(
        path: '/api/v1/emails',
        summary: 'メール一覧取得',
        security: [['bearerAuth' => []]],
        tags: ['Emails'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: '件名・送信者・本文で検索', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'unread', in: 'query', required: false, description: '未読のみ', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'category', in: 'query', required: false, description: 'カテゴリ', schema: new OA\Schema(type: 'string', enum: ['engineer', 'project'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: '1ページ件数', schema: new OA\Schema(type: 'integer', default: 30)),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
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
                  ->orWhere('from_name', 'like', "%{$search}%");
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

    #[OA\Get(
        path: '/api/v1/emails/{id}',
        summary: 'メール詳細取得（自動既読）',
        security: [['bearerAuth' => []]],
        tags: ['Emails'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'メールID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 404, description: '見つかりません'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    // メール詳細（自動既読）
    public function show(int $id)
    {
        $email = Email::with(['contact', 'deal', 'customer', 'attachments'])
            ->findOrFail($id);

        if (!$email->is_read) {
            $email->update(['is_read' => true]);
            // Gmail既読はレスポンス後に非同期実行
            defer(function () use ($email) {
                $token = GmailToken::where('tenant_id', $email->tenant_id)->first();
                if ($token && !str_starts_with($email->gmail_message_id, 'imap-')) {
                    try {
                        $this->gmailService->markAsRead($token, $email->gmail_message_id);
                    } catch (\Exception $e) {
                        Log::warning("markAsRead失敗: " . $e->getMessage());
                    }
                }
            });
        }

        return response()->json($email);
    }

    #[OA\Post(
        path: '/api/v1/emails/sync',
        summary: 'Gmailから最新メールを同期',
        security: [['bearerAuth' => []]],
        tags: ['Emails'],
        responses: [
            new OA\Response(response: 200, description: '同期成功'),
            new OA\Response(response: 401, description: 'Gmailトークン失効'),
            new OA\Response(response: 422, description: 'Gmail未接続'),
            new OA\Response(response: 500, description: '同期失敗'),
        ]
    )]
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

    #[OA\Patch(
        path: '/api/v1/emails/{id}/link',
        summary: 'メールを担当者・商談・顧客に紐付け',
        security: [['bearerAuth' => []]],
        tags: ['Emails'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'メールID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/emails/unread-count',
        summary: '未読メール件数取得',
        security: [['bearerAuth' => []]],
        tags: ['Emails'],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/emails/mark-all-read',
        summary: '全メールを既読にする',
        security: [['bearerAuth' => []]],
        tags: ['Emails'],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
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
