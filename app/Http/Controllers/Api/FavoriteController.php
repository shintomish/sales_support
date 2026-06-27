<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Engineer;
use App\Models\EngineerMailSource;
use App\Models\Favorite;
use App\Models\ProjectMailSource;
use App\Models\PublicProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 汎用お気に入り（技術者/案件・メール由来/登録を横断）。/mail-search と同じ行形式で一覧を返す。
 */
class FavoriteController extends Controller
{
    /** POST /favorites/toggle — お気に入りの登録/解除をトグル。 */
    public function toggle(Request $request): JsonResponse
    {
        $v = $request->validate([
            'target_type' => ['required', 'in:' . implode(',', Favorite::TYPES)],
            'target_id'   => ['required', 'integer'],
        ]);
        $user = Auth::user();

        // テナント越境防止: 対象が自テナントに存在することを確認（各モデルの GlobalScope=テナント絞り）
        $exists = match ($v['target_type']) {
            'project_mail'   => ProjectMailSource::whereKey($v['target_id'])->exists(),
            'public_project' => PublicProject::whereKey($v['target_id'])->exists(),
            'engineer_mail'  => EngineerMailSource::whereKey($v['target_id'])->exists(),
            'engineer'       => Engineer::whereKey($v['target_id'])->exists(),
            default          => false,
        };
        if (!$exists) {
            abort(404, '対象が見つかりません');
        }

        $existing = Favorite::where('user_id', $user->id)
            ->where('target_type', $v['target_type'])
            ->where('target_id', $v['target_id'])
            ->first();
        if ($existing) {
            $existing->delete();
            return response()->json(['favorited' => false]);
        }
        Favorite::create([
            'tenant_id'   => $user->tenant_id,
            'user_id'     => $user->id,
            'target_type' => $v['target_type'],
            'target_id'   => $v['target_id'],
        ]);
        return response()->json(['favorited' => true]);
    }

    /** GET /favorites/ids — ログインユーザーのお気に入りIDを種別ごとに返す（★表示用）。 */
    public function ids(): JsonResponse
    {
        $rows = Favorite::where('user_id', Auth::id())->get(['target_type', 'target_id']);
        $out = ['project_mail' => [], 'public_project' => [], 'engineer_mail' => [], 'engineer' => []];
        foreach ($rows as $r) {
            $out[$r->target_type][] = $r->target_id;
        }
        return response()->json($out);
    }

    /** GET /favorites?kind=project|engineer — お気に入り一覧（検索結果と同じ行形式）。 */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate(['kind' => ['required', 'in:project,engineer']]);
        $types = $v['kind'] === 'project' ? ['project_mail', 'public_project'] : ['engineer_mail', 'engineer'];
        $favs = Favorite::where('user_id', Auth::id())->whereIn('target_type', $types)->get();
        $byType = [];
        foreach ($favs as $f) $byType[$f->target_type][] = $f->target_id;

        $rows = [];
        if (!empty($byType['project_mail']))  $rows = array_merge($rows, $this->projectMailRows($byType['project_mail']));
        if (!empty($byType['public_project'])) $rows = array_merge($rows, $this->publicProjectRows($byType['public_project']));
        if (!empty($byType['engineer_mail'])) $rows = array_merge($rows, $this->engineerMailRows($byType['engineer_mail']));
        if (!empty($byType['engineer']))      $rows = array_merge($rows, $this->engineerRows($byType['engineer']));

        return response()->json(['data' => $rows, 'total' => count($rows)]);
    }

    private function projectMailRows(array $ids): array
    {
        return ProjectMailSource::with('email:id,received_at')->whereIn('id', $ids)->get()->map(fn($p) => [
            'source' => 'project_mail', 'source_label' => '案件メール', 'is_registered' => false,
            'id' => $p->id, 'title' => $p->title ?: '(件名なし)', 'sub' => $p->customer_name,
            'skills' => array_values(array_merge((array) ($p->required_skills ?? []), (array) ($p->preferred_skills ?? []))),
            'matched_skills' => [],
            'unit_price_min' => $p->unit_price_min !== null ? (float) $p->unit_price_min : null,
            'unit_price_max' => $p->unit_price_max !== null ? (float) $p->unit_price_max : null,
            'location' => $p->work_location,
            'date' => optional($p->email)->received_at?->toIso8601String() ?? $p->created_at?->toIso8601String(),
            'detail_url' => "/project-mails?select={$p->id}",
        ])->all();
    }

    private function publicProjectRows(array $ids): array
    {
        return PublicProject::with('skills:id,name')->whereIn('id', $ids)->get()->map(fn($p) => [
            'source' => 'public_project', 'source_label' => '登録案件', 'is_registered' => true,
            'id' => $p->id, 'title' => $p->title ?: '(無題)', 'sub' => null,
            'skills' => $p->skills->pluck('name')->all(), 'matched_skills' => [],
            'unit_price_min' => $p->unit_price_min !== null ? (float) $p->unit_price_min : null,
            'unit_price_max' => $p->unit_price_max !== null ? (float) $p->unit_price_max : null,
            'location' => $p->work_location,
            'date' => $p->published_at?->toIso8601String() ?? $p->created_at?->toIso8601String(),
            'detail_url' => "/public-projects/{$p->id}",
        ])->all();
    }

    private function engineerMailRows(array $ids): array
    {
        return EngineerMailSource::with('email:id,received_at')->whereIn('id', $ids)->get()->map(fn($e) => [
            'source' => 'engineer_mail', 'source_label' => '技術者メール', 'is_registered' => false,
            'id' => $e->id, 'title' => $e->name ?: '(氏名なし)', 'sub' => $e->affiliation,
            'skills' => array_values((array) ($e->skills ?? [])), 'matched_skills' => [],
            'unit_price_min' => $e->unit_price_min !== null ? (float) $e->unit_price_min : null,
            'unit_price_max' => $e->unit_price_max !== null ? (float) $e->unit_price_max : null,
            'location' => $e->nearest_station,
            'date' => optional($e->email)->received_at?->toIso8601String() ?? $e->created_at?->toIso8601String(),
            'detail_url' => "/engineer-mails?select={$e->id}",
        ])->all();
    }

    private function engineerRows(array $ids): array
    {
        return Engineer::with(['engineerSkills.skill:id,name', 'profile:id,engineer_id,desired_unit_price_min,desired_unit_price_max'])
            ->whereIn('id', $ids)->get()->map(function ($e) {
                $isSelf = ($e->affiliation_type ?? '') === 'self';
                return [
                    'source' => 'engineer', 'source_label' => $isSelf ? '登録技術者(自社)' : '登録技術者(BP)', 'is_registered' => true,
                    'id' => $e->id, 'title' => $e->name ?: '(氏名なし)', 'sub' => $e->affiliation,
                    'skills' => $e->engineerSkills->map(fn($es) => $es->skill?->name)->filter()->values()->all(), 'matched_skills' => [],
                    'unit_price_min' => $e->profile?->desired_unit_price_min !== null ? (float) $e->profile->desired_unit_price_min : null,
                    'unit_price_max' => $e->profile?->desired_unit_price_max !== null ? (float) $e->profile->desired_unit_price_max : null,
                    'location' => $e->nearest_station,
                    'date' => $e->created_at?->toIso8601String(),
                    'detail_url' => "/engineers/{$e->id}",
                ];
            })->all();
    }
}
