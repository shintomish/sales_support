<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\FeedbackNotification;
use App\Models\FeedbackReport;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * 社内バグ・要望フィードバック
 *
 * - POST /api/v1/feedback                投稿（要ログイン・全ロール可）
 * - GET  /api/v1/admin/feedback          一覧（super_admin のみ・全テナント横断）
 * - PATCH /api/v1/admin/feedback/{id}    status 更新（super_admin のみ）
 *
 * 投稿時に FEEDBACK_NOTIFY_TO（既定: y-shintomi@aizen-sol.co.jp）にメール通知。
 */
class FeedbackController extends Controller
{
    private const TYPES    = [FeedbackReport::TYPE_BUG, FeedbackReport::TYPE_REQUEST, FeedbackReport::TYPE_OTHER];
    private const STATUSES = [FeedbackReport::STATUS_NEW, FeedbackReport::STATUS_SEEN, FeedbackReport::STATUS_CLOSED];

    /** POST /api/v1/feedback */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'    => ['required', Rule::in(self::TYPES)],
            'subject' => ['required', 'string', 'max:255'],
            'body'    => ['required', 'string', 'max:10000'],
            'url'     => ['nullable', 'string', 'max:500'],
        ]);

        $user = Auth::user();

        $feedback = FeedbackReport::create([
            'tenant_id'  => $user->tenant_id,
            'user_id'    => $user->id,
            'type'       => $validated['type'],
            'subject'    => $validated['subject'],
            'body'       => $validated['body'],
            'url'        => $validated['url'] ?? null,
            'user_agent' => substr((string) $request->header('User-Agent', ''), 0, 500) ?: null,
            'status'     => FeedbackReport::STATUS_NEW,
        ]);

        $this->notify($feedback, $user);

        return response()->json($feedback, 201);
    }

    /** GET /api/v1/admin/feedback */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin();

        $status   = $request->query('status');
        $type     = $request->query('type');
        $tenantId = $request->query('tenant_id');
        $perPage  = min(200, max(1, (int) $request->query('per_page', 50)));

        $q = FeedbackReport::withoutGlobalScope(TenantScope::class)
            ->with(['user:id,name,email,tenant_id', 'tenant:id,name'])
            ->orderByDesc('created_at');

        if ($status && in_array($status, self::STATUSES, true)) {
            $q->where('status', $status);
        }
        if ($type && in_array($type, self::TYPES, true)) {
            $q->where('type', $type);
        }
        if ($tenantId !== null && $tenantId !== '' && ctype_digit((string) $tenantId)) {
            $q->where('tenant_id', (int) $tenantId);
        }

        // フロント /admin/feedback は paginate 形式 (data/current_page/last_page/total) を期待。
        // items キーは旧 API 互換のため残しておく (data と同じ参照)。
        $paginated = $q->paginate($perPage);
        return response()->json([
            'items'        => $paginated->items(),
            'data'         => $paginated->items(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
        ]);
    }

    /** PATCH /api/v1/admin/feedback/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuperAdmin();

        $validated = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        $feedback = FeedbackReport::withoutGlobalScope(TenantScope::class)->findOrFail($id);
        $feedback->status = $validated['status'];
        $feedback->save();

        return response()->json($feedback);
    }

    private function authorizeSuperAdmin(): void
    {
        $user = Auth::user();
        if (!$user || !method_exists($user, 'isSuperAdmin') || !$user->isSuperAdmin()) {
            abort(403, 'super_admin のみアクセス可能です');
        }
    }

    private function notify(FeedbackReport $feedback, User $user): void
    {
        $to = config('mail.feedback_notify_to');
        if (!$to) {
            Log::warning('FeedbackNotification skipped: FEEDBACK_NOTIFY_TO not set', ['feedback_id' => $feedback->id]);
            return;
        }

        try {
            $tenantName = Tenant::withoutGlobalScopes()->where('id', $user->tenant_id)->value('name');

            Mail::to($to)->send(new FeedbackNotification(
                feedback:   $feedback,
                tenantName: $tenantName,
                userName:   $user->name,
                userEmail:  $user->email,
            ));
        } catch (Throwable $e) {
            Log::warning('FeedbackNotification send failed', [
                'feedback_id' => $feedback->id,
                'err'         => $e->getMessage(),
            ]);
        }
    }
}
