<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SupabaseAuthAdminService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    use AuthorizesRequests;

    /**
     * テナント内ユーザー一覧（担当者フィルタ用）
     * super_admin は全テナント or ?tenant_id= で絞り込み可
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = User::select('id', 'name', 'email', 'role', 'tenant_id', 'supabase_uid', 'created_at')
            ->orderBy('name');

        if ($user->isSuperAdmin()) {
            if ($request->tenant_id) {
                $query->where('tenant_id', $request->tenant_id);
            }
        } else {
            $query->where('tenant_id', $user->tenant_id);
        }

        if ($request->filled('search')) {
            $s = $request->input('search');
            $query->where(fn($q) =>
                $q->where('name', 'ilike', "%{$s}%")->orWhere('email', 'ilike', "%{$s}%")
            );
        }

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        return response()->json($query->get());
    }

    /**
     * 新規ユーザー作成 + 招待メール送信
     * tenant_admin: 自テナントのみ・super_admin 作成不可
     * super_admin: 任意のテナント・任意ロール
     */
    public function store(Request $request, SupabaseAuthAdminService $authAdmin)
    {
        $actor = $request->user();
        $this->authorize('manage', User::class);

        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|max:255|unique:users,email',
            'role'      => ['required', Rule::in(['super_admin', 'tenant_admin', 'tenant_user'])],
            'tenant_id' => 'nullable|integer|exists:tenants,id',
        ]);

        $targetTenantId = $validated['tenant_id'] ?? $actor->tenant_id;

        // tenant_admin は自テナントのみ
        if ($actor->isTenantAdmin() && $actor->tenant_id !== $targetTenantId) {
            abort(403, '自テナント以外のユーザーを作成できません');
        }

        // tenant_admin は super_admin を作成不可
        if ($validated['role'] === 'super_admin' && !$actor->isSuperAdmin()) {
            abort(403, 'super_admin ロールを付与できるのは super_admin のみです');
        }

        $redirectTo = $this->frontendResetUrl();

        $user = DB::transaction(function () use ($validated, $targetTenantId, $authAdmin, $redirectTo) {
            $supabaseUid = $authAdmin->inviteUser($validated['email'], $redirectTo);

            return User::create([
                'name'         => $validated['name'],
                'email'        => $validated['email'],
                'password'     => bcrypt(Str::random(40)),
                'role'         => $validated['role'],
                'tenant_id'    => $targetTenantId,
                'supabase_uid' => $supabaseUid,
            ]);
        });

        return response()->json($user, 201);
    }

    /**
     * ユーザー情報を更新（name / email / role）
     */
    public function update(Request $request, int $id, SupabaseAuthAdminService $authAdmin)
    {
        $actor  = $request->user();
        $target = User::findOrFail($id);
        $this->authorize('manage', User::class);

        // tenant_admin は自テナントのみ
        if ($actor->isTenantAdmin() && $actor->tenant_id !== $target->tenant_id) {
            abort(403, '自テナント以外のユーザーを編集できません');
        }

        $validated = $request->validate([
            'name'  => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            'role'  => ['sometimes', 'required', Rule::in(['super_admin', 'tenant_admin', 'tenant_user'])],
        ]);

        // role 変更時のチェック
        if (array_key_exists('role', $validated) && $validated['role'] !== $target->role) {
            // 自分のロール変更は不可
            if ($actor->id === $target->id) {
                abort(403, '自分のロールは変更できません');
            }
            // tenant_admin は super_admin の作成・降格不可
            if (!$actor->isSuperAdmin()) {
                if ($validated['role'] === 'super_admin' || $target->role === 'super_admin') {
                    abort(403, 'super_admin ロールを操作できるのは super_admin のみです');
                }
            }
        }

        // email 変更時は Supabase Auth 側も更新
        if (isset($validated['email']) && $validated['email'] !== $target->email && $target->supabase_uid) {
            $authAdmin->updateUser($target->supabase_uid, ['email' => $validated['email']]);
        }

        $target->update($validated);

        return response()->json($target);
    }

    /**
     * ユーザー削除（Supabase Auth 完全削除 + Laravel users 物理削除）
     */
    public function destroy(Request $request, int $id, SupabaseAuthAdminService $authAdmin)
    {
        $actor  = $request->user();
        $target = User::findOrFail($id);
        $this->authorize('manage', User::class);

        if ($actor->id === $target->id) {
            abort(403, '自分自身は削除できません');
        }
        if ($actor->isTenantAdmin() && $actor->tenant_id !== $target->tenant_id) {
            abort(403, '自テナント以外のユーザーを削除できません');
        }
        if (!$actor->isSuperAdmin() && $target->role === 'super_admin') {
            abort(403, 'super_admin を削除できるのは super_admin のみです');
        }

        DB::transaction(function () use ($target, $authAdmin) {
            if ($target->supabase_uid) {
                $authAdmin->deleteUser($target->supabase_uid);
            }
            $target->delete();
        });

        return response()->json(null, 204);
    }

    /**
     * 招待メール（パスワード設定リンク）を再送
     */
    public function resendInvite(Request $request, int $id, SupabaseAuthAdminService $authAdmin)
    {
        $actor  = $request->user();
        $target = User::findOrFail($id);
        $this->authorize('manage', User::class);

        if ($actor->isTenantAdmin() && $actor->tenant_id !== $target->tenant_id) {
            abort(403, '自テナント以外のユーザーには再送できません');
        }

        $authAdmin->sendRecovery($target->email, $this->frontendResetUrl());

        return response()->json(['message' => '招待メールを再送しました']);
    }

    private function frontendResetUrl(): string
    {
        $base = rtrim(config('app.frontend_url', 'https://app.ai-mon.net'), '/');
        return $base . '/reset-password';
    }
}
