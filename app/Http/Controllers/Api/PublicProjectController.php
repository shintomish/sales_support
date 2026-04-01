<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PublicProject;
use App\Models\ProjectRequiredSkill;
use App\Models\FavoriteProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicProjectController extends Controller
{
    private function formatProject(PublicProject $p, ?int $userId = null): array
    {
        return [
            'id'                       => $p->id,
            'title'                    => $p->title,
            'description'              => $p->description,
            'end_client'               => $p->end_client,
            'posted_by_customer_id'    => $p->posted_by_customer_id,
            'posted_by_customer_name'  => $p->postedByCustomer?->company_name,
            'unit_price_min'           => $p->unit_price_min,
            'unit_price_max'           => $p->unit_price_max,
            'contract_type'            => $p->contract_type,
            'contract_period_months'   => $p->contract_period_months,
            'start_date'               => $p->start_date,
            'deduction_hours'          => $p->deduction_hours,
            'overtime_hours'           => $p->overtime_hours,
            'settlement_unit_minutes'  => $p->settlement_unit_minutes,
            'work_location'            => $p->work_location,
            'nearest_station'          => $p->nearest_station,
            'work_style'               => $p->work_style,
            'remote_frequency'         => $p->remote_frequency,
            'required_experience_years'=> $p->required_experience_years,
            'team_size'                => $p->team_size,
            'interview_count'          => $p->interview_count,
            'headcount'                => $p->headcount,
            'status'                   => $p->status,
            'views_count'              => $p->views_count,
            'applications_count'       => $p->applications_count,
            'published_at'             => $p->published_at,
            'expires_at'               => $p->expires_at,
            'required_skills'          => $p->requiredSkills->map(fn($rs) => [
                'skill_id'             => $rs->skill_id,
                'skill_name'           => $rs->skill?->name,
                'category'             => $rs->skill?->category,
                'is_required'          => $rs->is_required,
                'min_experience_years' => $rs->min_experience_years,
            ])->values(),
            'is_favorite' => $userId
                ? $p->favoriteByUsers->where('user_id', $userId)->isNotEmpty()
                : false,
            'created_at' => $p->created_at,
            'updated_at' => $p->updated_at,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $userId   = auth()->id();

        $query = PublicProject::with(['requiredSkills.skill', 'postedByCustomer', 'favoriteByUsers'])
            ->where('tenant_id', $tenantId);

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        } else {
            $query->where('status', '!=', 'filled');
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        if ($workStyle = $request->get('work_style')) {
            $query->where('work_style', $workStyle);
        }

        if ($minPrice = $request->get('unit_price_min')) {
            $query->where('unit_price_max', '>=', $minPrice);
        }

        if ($skillId = $request->get('skill_id')) {
            $query->whereHas('requiredSkills', fn($q) => $q->where('skill_id', $skillId));
        }

        $paginated = $query->orderByDesc('published_at')->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => $paginated->map(fn(PublicProject $p) => $this->formatProject($p, $userId)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $userId   = auth()->id();

        $project = PublicProject::with(['requiredSkills.skill', 'postedByCustomer', 'favoriteByUsers'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        // 閲覧数インクリメント（非同期で問題ないが簡易実装）
        $project->increment('views_count');

        \App\Models\ProjectView::create([
            'tenant_id'      => $tenantId,
            'project_id'     => $project->id,
            'viewer_user_id' => $userId,
        ]);

        return response()->json(['data' => $this->formatProject($project, $userId)]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $v = $request->validate([
            'title'                    => 'required|string|max:200',
            'description'              => 'nullable|string',
            'end_client'               => 'nullable|string|max:200',
            'posted_by_customer_id'    => 'nullable|integer|exists:customers,id',
            'unit_price_min'           => 'nullable|numeric|min:0',
            'unit_price_max'           => 'nullable|numeric|min:0',
            'contract_type'            => 'nullable|in:準委任,派遣,請負',
            'contract_period_months'   => 'nullable|integer|min:1',
            'start_date'               => 'nullable|date',
            'deduction_hours'          => 'nullable|numeric|min:0',
            'overtime_hours'           => 'nullable|numeric|min:0',
            'settlement_unit_minutes'  => 'nullable|integer|in:15,30,60',
            'work_location'            => 'nullable|string|max:200',
            'nearest_station'          => 'nullable|string|max:100',
            'work_style'               => 'nullable|in:remote,office,hybrid',
            'remote_frequency'         => 'nullable|string|max:50',
            'required_experience_years'=> 'nullable|integer|min:0',
            'team_size'                => 'nullable|integer|min:1',
            'interview_count'          => 'nullable|integer|min:0',
            'headcount'                => 'nullable|integer|min:1',
            'published_at'             => 'nullable|date',
            'expires_at'               => 'nullable|date',
            // 必須スキル
            'skills'                           => 'nullable|array',
            'skills.*.skill_id'                => 'required_with:skills|integer|exists:skills,id',
            'skills.*.is_required'             => 'nullable|boolean',
            'skills.*.min_experience_years'    => 'nullable|numeric|min:0',
        ]);

        $project = DB::transaction(function () use ($v, $tenantId) {
            $project = PublicProject::create(array_merge(
                array_filter([
                    'posted_by_customer_id'     => $v['posted_by_customer_id'] ?? null,
                    'title'                     => $v['title'],
                    'description'               => $v['description'] ?? null,
                    'end_client'                => $v['end_client'] ?? null,
                    'unit_price_min'            => $v['unit_price_min'] ?? null,
                    'unit_price_max'            => $v['unit_price_max'] ?? null,
                    'contract_type'             => $v['contract_type'] ?? null,
                    'contract_period_months'    => $v['contract_period_months'] ?? null,
                    'start_date'                => $v['start_date'] ?? null,
                    'deduction_hours'           => $v['deduction_hours'] ?? null,
                    'overtime_hours'            => $v['overtime_hours'] ?? null,
                    'settlement_unit_minutes'   => $v['settlement_unit_minutes'] ?? null,
                    'work_location'             => $v['work_location'] ?? null,
                    'nearest_station'           => $v['nearest_station'] ?? null,
                    'work_style'                => $v['work_style'] ?? null,
                    'remote_frequency'          => $v['remote_frequency'] ?? null,
                    'required_experience_years' => $v['required_experience_years'] ?? null,
                    'team_size'                 => $v['team_size'] ?? null,
                    'interview_count'           => $v['interview_count'] ?? null,
                    'headcount'                 => $v['headcount'] ?? 1,
                    'published_at'              => $v['published_at'] ?? now(),
                    'expires_at'                => $v['expires_at'] ?? null,
                ], fn($val) => $val !== null),
                [
                    'tenant_id'         => $tenantId,
                    'posted_by_user_id' => auth()->id(),
                    'status'            => 'open',
                ]
            ));

            foreach ($v['skills'] ?? [] as $skill) {
                ProjectRequiredSkill::create([
                    'project_id'           => $project->id,
                    'skill_id'             => $skill['skill_id'],
                    'is_required'          => $skill['is_required'] ?? true,
                    'min_experience_years' => $skill['min_experience_years'] ?? null,
                ]);
            }

            return $project;
        });

        $project->load(['requiredSkills.skill', 'postedByCustomer', 'favoriteByUsers']);

        return response()->json(['data' => $this->formatProject($project, auth()->id())], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $project  = PublicProject::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'title'                    => 'sometimes|string|max:200',
            'description'              => 'nullable|string',
            'end_client'               => 'nullable|string|max:200',
            'posted_by_customer_id'    => 'nullable|integer|exists:customers,id',
            'unit_price_min'           => 'nullable|numeric|min:0',
            'unit_price_max'           => 'nullable|numeric|min:0',
            'contract_type'            => 'nullable|in:準委任,派遣,請負',
            'contract_period_months'   => 'nullable|integer|min:1',
            'start_date'               => 'nullable|date',
            'deduction_hours'          => 'nullable|numeric|min:0',
            'overtime_hours'           => 'nullable|numeric|min:0',
            'settlement_unit_minutes'  => 'nullable|integer|in:15,30,60',
            'work_location'            => 'nullable|string|max:200',
            'nearest_station'          => 'nullable|string|max:100',
            'work_style'               => 'nullable|in:remote,office,hybrid',
            'remote_frequency'         => 'nullable|string|max:50',
            'required_experience_years'=> 'nullable|integer|min:0',
            'team_size'                => 'nullable|integer|min:1',
            'interview_count'          => 'nullable|integer|min:0',
            'headcount'                => 'nullable|integer|min:1',
            'status'                   => 'nullable|in:open,closed,filled',
            'published_at'             => 'nullable|date',
            'expires_at'               => 'nullable|date',
            'skills'                   => 'nullable|array',
            'skills.*.skill_id'        => 'required_with:skills|integer|exists:skills,id',
            'skills.*.is_required'     => 'nullable|boolean',
            'skills.*.min_experience_years' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($v, $project) {
            $project->update(array_filter($v, fn($val, $key) =>
                $val !== null && !in_array($key, ['skills']),
                ARRAY_FILTER_USE_BOTH
            ));

            if (array_key_exists('skills', $v)) {
                ProjectRequiredSkill::where('project_id', $project->id)->delete();
                foreach ($v['skills'] ?? [] as $skill) {
                    ProjectRequiredSkill::create([
                        'project_id'           => $project->id,
                        'skill_id'             => $skill['skill_id'],
                        'is_required'          => $skill['is_required'] ?? true,
                        'min_experience_years' => $skill['min_experience_years'] ?? null,
                    ]);
                }
            }
        });

        $project->load(['requiredSkills.skill', 'postedByCustomer', 'favoriteByUsers']);

        return response()->json(['data' => $this->formatProject($project, auth()->id())]);
    }

    public function destroy(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        PublicProject::where('tenant_id', $tenantId)->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    public function toggleFavorite(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $userId   = auth()->id();

        PublicProject::where('tenant_id', $tenantId)->findOrFail($id);

        $existing = FavoriteProject::where('project_id', $id)->where('user_id', $userId)->first();
        if ($existing) {
            $existing->delete();
            return response()->json(['favorited' => false]);
        }

        FavoriteProject::create([
            'tenant_id'  => $tenantId,
            'user_id'    => $userId,
            'project_id' => $id,
        ]);

        return response()->json(['favorited' => true]);
    }
}
