<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SkillAlias;
use App\Services\SkillDictionary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * スキル同義語辞書（skill_aliases）の管理。
 *   - 一覧(index)は全ユーザー閲覧可
 *   - 追加/削除/改名は管理者(tenant_admin / super_admin)のみ（辞書は全テナント共通のグローバルデータ）
 *   - 書き換え後は SkillDictionary のキャッシュを破棄して即時反映
 */
class SkillAliasController extends Controller
{
    /** GET /skill-aliases — canonical でグループ化して返す。 */
    public function index(): JsonResponse
    {
        $groups = SkillAlias::orderBy('canonical')->orderBy('alias')->get()
            ->groupBy('canonical')
            ->map(fn($rows, $canonical) => [
                'canonical' => $canonical,
                'aliases'   => $rows->map(fn($r) => ['id' => $r->id, 'alias' => $r->alias])->values()->all(),
            ])
            ->values();

        return response()->json(['data' => $groups]);
    }

    /** POST /skill-aliases — 表記揺れを1件追加（canonical 自身も alias として登録可）。 */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin();
        $v = $request->validate([
            'canonical' => ['required', 'string', 'max:80'],
            'alias'     => ['required', 'string', 'max:80'],
        ]);
        $canonical = trim($v['canonical']);
        $alias     = trim($v['alias']);
        if ($canonical === '' || $alias === '') {
            return response()->json(['message' => '正規名・別名は必須です'], 422);
        }
        if (SkillAlias::where('alias', $alias)->exists()) {
            return response()->json(['message' => "「{$alias}」は既に登録されています"], 422);
        }
        $row = SkillAlias::create(['canonical' => $canonical, 'alias' => $alias]);
        SkillDictionary::forgetCache();

        return response()->json(['id' => $row->id, 'canonical' => $row->canonical, 'alias' => $row->alias], 201);
    }

    /** DELETE /skill-aliases/{id} — 表記揺れを1件削除。 */
    public function destroy(int $id): JsonResponse
    {
        $this->authorizeAdmin();
        $row = SkillAlias::find($id);
        if (!$row) {
            return response()->json(['message' => '対象が見つかりません'], 404);
        }
        $row->delete();
        SkillDictionary::forgetCache();

        return response()->json(['deleted' => true]);
    }

    /** DELETE /skill-aliases/group — canonical グループごと削除。 */
    public function destroyGroup(Request $request): JsonResponse
    {
        $this->authorizeAdmin();
        $v = $request->validate(['canonical' => ['required', 'string', 'max:80']]);
        $deleted = SkillAlias::where('canonical', trim($v['canonical']))->delete();
        SkillDictionary::forgetCache();

        return response()->json(['deleted' => $deleted]);
    }

    /** PUT /skill-aliases/rename — グループの正規名を一括変更。 */
    public function rename(Request $request): JsonResponse
    {
        $this->authorizeAdmin();
        $v = $request->validate([
            'from' => ['required', 'string', 'max:80'],
            'to'   => ['required', 'string', 'max:80'],
        ]);
        $from = trim($v['from']);
        $to   = trim($v['to']);
        if ($to === '') {
            return response()->json(['message' => '新しい正規名は必須です'], 422);
        }
        $updated = SkillAlias::where('canonical', $from)->update(['canonical' => $to]);
        SkillDictionary::forgetCache();

        return response()->json(['updated' => $updated]);
    }

    /** 管理者(tenant_admin / super_admin)のみ許可。 */
    private function authorizeAdmin(): void
    {
        $user = Auth::user();
        if (!$user || !in_array($user->role, ['super_admin', 'tenant_admin'], true)) {
            abort(403, '辞書の編集は管理者のみ可能です');
        }
    }
}
