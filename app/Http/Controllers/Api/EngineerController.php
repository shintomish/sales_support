<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Engineer;
use App\Models\EngineerProfile;
use App\Models\EngineerSkill;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EngineerController extends Controller
{
    private function formatEngineer(Engineer $e): array
    {
        $p = $e->profile;
        return [
            'id'                      => $e->id,
            'name'                    => $e->name,
            'name_kana'               => $e->name_kana,
            'email'                   => $e->email,
            'phone'                   => $e->phone,
            'affiliation'             => $e->affiliation,
            'affiliation_contact'     => $e->affiliation_contact,
            'age'                     => $e->age,
            'nationality'             => $e->nationality,
            'affiliation_type'        => $e->affiliation_type,
            'profile' => $p ? [
                'desired_unit_price_min' => $p->desired_unit_price_min,
                'desired_unit_price_max' => $p->desired_unit_price_max,
                'available_from'         => $p->available_from,
                'availability_status'    => $p->availability_status,
                'past_client_count'      => $p->past_client_count,
                'work_style'             => $p->work_style,
                'preferred_location'     => $p->preferred_location,
                'self_introduction'      => $p->self_introduction,
                'resume_file_path'       => $p->resume_file_path,
                'github_url'             => $p->github_url,
                'portfolio_url'          => $p->portfolio_url,
                'is_public'              => $p->is_public,
            ] : null,
            'skills' => $e->engineerSkills->map(fn($es) => [
                'skill_id'          => $es->skill_id,
                'skill_name'        => $es->skill?->name,
                'category'          => $es->skill?->category,
                'experience_years'  => $es->experience_years,
                'proficiency_level' => $es->proficiency_level,
            ])->values(),
            'created_at' => $e->created_at,
            'updated_at' => $e->updated_at,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $query = Engineer::with(['profile', 'engineerSkills.skill'])
            ->where('tenant_id', $tenantId);

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('affiliation', 'ilike', "%{$search}%");
            });
        }

        if ($skill = $request->get('skill_id')) {
            $query->whereHas('engineerSkills', fn($q) => $q->where('skill_id', $skill));
        }

        if ($workStyle = $request->get('work_style')) {
            $query->whereHas('profile', fn($q) => $q->where('work_style', $workStyle));
        }

        if ($request->boolean('available_only')) {
            $query->whereHas('profile', fn($q) => $q->where('available_from', '<=', now()->toDateString()));
        }

        // 稼働可能日ソート用に engineer_profiles を JOIN
        if ($request->get('sort_by') === 'available_from') {
            $query->leftJoin('engineer_profiles', 'engineers.id', '=', 'engineer_profiles.engineer_id')
                  ->select('engineers.*');
        }
        $paginated = $query->orderBy(...$this->resolveSort($request, [
            'name'         => 'engineers.name',
            'affiliation'  => 'engineers.affiliation',
            'available_from' => 'engineer_profiles.available_from',
        ], 'engineers.name', 'asc'))->paginate($request->get('per_page', 30));

        return response()->json([
            'data' => $paginated->map(fn(Engineer $e) => $this->formatEngineer($e)),
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
        $engineer = Engineer::with(['profile', 'engineerSkills.skill'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json(['data' => $this->formatEngineer($engineer)]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $v = $request->validate([
            'name'                    => 'required|string|max:100',
            'name_kana'               => 'nullable|string|max:100',
            'email'                   => 'nullable|email|max:200',
            'phone'                   => 'nullable|string|max:50',
            'affiliation'             => 'nullable|string|max:100',
            'affiliation_contact'     => 'nullable|string|max:100',
            'age'                     => 'nullable|integer|min:18|max:80',
            'nationality'             => 'nullable|string|max:100',
            'affiliation_type'        => 'nullable|in:self,bp',
            // プロフィール
            'desired_unit_price_min'  => 'nullable|numeric|min:0',
            'desired_unit_price_max'  => 'nullable|numeric|min:0',
            'available_from'          => 'nullable|date',
            'availability_status'     => 'nullable|in:available,working,scheduled',
            'past_client_count'       => 'nullable|integer|min:0',
            'work_style'              => 'nullable|in:remote,office,hybrid',
            'preferred_location'      => 'nullable|string|max:100',
            'self_introduction'       => 'nullable|string',
            'github_url'              => 'nullable|url|max:200',
            'portfolio_url'           => 'nullable|url|max:200',
            'is_public'               => 'nullable|boolean',
            // スキル
            'skills'                  => 'nullable|array',
            'skills.*.skill_id'       => 'required_with:skills|integer|exists:skills,id',
            'skills.*.experience_years' => 'nullable|numeric|min:0|max:50',
            'skills.*.proficiency_level' => 'nullable|integer|between:1,5',
        ]);

        $engineer = DB::transaction(function () use ($v, $tenantId) {
            $engineer = Engineer::create([
                'tenant_id'           => $tenantId,
                'name'                => $v['name'],
                'name_kana'           => $v['name_kana'] ?? null,
                'email'               => $v['email'] ?? null,
                'phone'               => $v['phone'] ?? null,
                'affiliation'         => $v['affiliation'] ?? null,
                'affiliation_contact' => $v['affiliation_contact'] ?? null,
                'age'                 => $v['age'] ?? null,
                'nationality'         => $v['nationality'] ?? null,
                'affiliation_type'    => $v['affiliation_type'] ?? null,
            ]);

            EngineerProfile::create([
                'tenant_id'              => $tenantId,
                'engineer_id'            => $engineer->id,
                'desired_unit_price_min' => $v['desired_unit_price_min'] ?? null,
                'desired_unit_price_max' => $v['desired_unit_price_max'] ?? null,
                'available_from'         => $v['available_from'] ?? null,
                'availability_status'    => $v['availability_status'] ?? 'available',
                'past_client_count'      => $v['past_client_count'] ?? null,
                'work_style'             => $v['work_style'] ?? null,
                'preferred_location'     => $v['preferred_location'] ?? null,
                'self_introduction'      => $v['self_introduction'] ?? null,
                'github_url'             => $v['github_url'] ?? null,
                'portfolio_url'          => $v['portfolio_url'] ?? null,
                'is_public'              => $v['is_public'] ?? false,
            ]);

            if (!empty($v['skills'])) {
                foreach ($v['skills'] as $skill) {
                    EngineerSkill::create([
                        'tenant_id'         => $tenantId,
                        'engineer_id'       => $engineer->id,
                        'skill_id'          => $skill['skill_id'],
                        'experience_years'  => $skill['experience_years'] ?? 0,
                        'proficiency_level' => $skill['proficiency_level'] ?? 3,
                    ]);
                }
            }

            return $engineer;
        });

        $engineer->load(['profile', 'engineerSkills.skill']);

        return response()->json(['data' => $this->formatEngineer($engineer)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $engineer = Engineer::where('tenant_id', $tenantId)->findOrFail($id);

        $v = $request->validate([
            'name'                    => 'sometimes|string|max:100',
            'name_kana'               => 'nullable|string|max:100',
            'email'                   => 'nullable|email|max:200',
            'phone'                   => 'nullable|string|max:50',
            'affiliation'             => 'nullable|string|max:100',
            'affiliation_contact'     => 'nullable|string|max:100',
            'age'                     => 'nullable|integer|min:18|max:80',
            'nationality'             => 'nullable|string|max:100',
            'affiliation_type'        => 'nullable|in:self,bp',
            'desired_unit_price_min'  => 'nullable|numeric|min:0',
            'desired_unit_price_max'  => 'nullable|numeric|min:0',
            'available_from'          => 'nullable|date',
            'availability_status'     => 'nullable|in:available,working,scheduled',
            'past_client_count'       => 'nullable|integer|min:0',
            'work_style'              => 'nullable|in:remote,office,hybrid',
            'preferred_location'      => 'nullable|string|max:100',
            'self_introduction'       => 'nullable|string',
            'github_url'              => 'nullable|url|max:200',
            'portfolio_url'           => 'nullable|url|max:200',
            'is_public'               => 'nullable|boolean',
            'skills'                  => 'nullable|array',
            'skills.*.skill_id'       => 'required_with:skills|integer|exists:skills,id',
            'skills.*.experience_years' => 'nullable|numeric|min:0|max:50',
            'skills.*.proficiency_level' => 'nullable|integer|between:1,5',
        ]);

        DB::transaction(function () use ($v, $engineer, $tenantId) {
            $engineerFields = array_filter([
                'name'                => $v['name'] ?? null,
                'name_kana'           => $v['name_kana'] ?? null,
                'email'               => $v['email'] ?? null,
                'phone'               => $v['phone'] ?? null,
                'affiliation'         => $v['affiliation'] ?? null,
                'affiliation_contact' => $v['affiliation_contact'] ?? null,
                'age'                 => $v['age'] ?? null,
                'nationality'         => $v['nationality'] ?? null,
                'affiliation_type'    => $v['affiliation_type'] ?? null,
            ], fn($val) => $val !== null);
            $engineer->update($engineerFields);

            $profileFields = array_filter([
                'desired_unit_price_min' => $v['desired_unit_price_min'] ?? null,
                'desired_unit_price_max' => $v['desired_unit_price_max'] ?? null,
                'available_from'         => $v['available_from'] ?? null,
                'availability_status'    => $v['availability_status'] ?? null,
                'past_client_count'      => $v['past_client_count'] ?? null,
                'work_style'             => $v['work_style'] ?? null,
                'preferred_location'     => $v['preferred_location'] ?? null,
                'self_introduction'      => $v['self_introduction'] ?? null,
                'github_url'             => $v['github_url'] ?? null,
                'portfolio_url'          => $v['portfolio_url'] ?? null,
                'is_public'              => isset($v['is_public']) ? $v['is_public'] : null,
            ], fn($val) => $val !== null);

            if (!empty($profileFields)) {
                EngineerProfile::updateOrCreate(
                    ['engineer_id' => $engineer->id],
                    array_merge(['tenant_id' => $tenantId], $profileFields)
                );
            }

            // スキルが指定された場合は全置換
            if (array_key_exists('skills', $v)) {
                EngineerSkill::where('engineer_id', $engineer->id)->delete();
                foreach ($v['skills'] ?? [] as $skill) {
                    EngineerSkill::create([
                        'tenant_id'         => $tenantId,
                        'engineer_id'       => $engineer->id,
                        'skill_id'          => $skill['skill_id'],
                        'experience_years'  => $skill['experience_years'] ?? 0,
                        'proficiency_level' => $skill['proficiency_level'] ?? 3,
                    ]);
                }
            }
        });

        $engineer->load(['profile', 'engineerSkills.skill']);

        return response()->json(['data' => $this->formatEngineer($engineer)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        Engineer::where('tenant_id', $tenantId)->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
