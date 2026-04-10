<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Engineer;
use App\Models\PublicProject;
use App\Models\Skill;
use App\Services\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class MatchingController extends Controller
{
    public function __construct(private MatchingService $matchingService) {}

    #[OA\Get(
        path: '/api/v1/matching/projects/{id}/engineers',
        summary: '案件に対するおすすめ技術者一覧',
        security: [['bearerAuth' => []]],
        tags: ['Matching'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '案件ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'スコア付き技術者一覧'),
            new OA\Response(response: 404, description: '案件が見つかりません'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function recommendEngineers(int $projectId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $project = PublicProject::with(['requiredSkills.skill'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($projectId);

        $recommendations = $this->matchingService->recommendEngineers($project);

        return response()->json([
            'data' => $recommendations->map(fn($item) => [
                'engineer_id'              => $item['engineer']->id,
                'engineer_name'            => $item['engineer']->name,
                'affiliation'              => $item['engineer']->affiliation,
                'score'                    => $item['score'],
                'score_badge'              => $this->scoreBadge($item['score']),
                'skill_match_score'        => $item['skill_match_score'],
                'price_match_score'        => $item['price_match_score'],
                'location_match_score'     => $item['location_match_score'],
                'availability_match_score' => $item['availability_match_score'],
                'available_from'           => $item['engineer']->profile?->available_from,
                'work_style'               => $item['engineer']->profile?->work_style,
                'desired_unit_price_min'   => $item['engineer']->profile?->desired_unit_price_min,
                'desired_unit_price_max'   => $item['engineer']->profile?->desired_unit_price_max,
                'skills'                   => $item['engineer']->engineerSkills->map(fn($es) => [
                    'name'             => $es->skill?->name,
                    'experience_years' => $es->experience_years,
                ])->values(),
            ]),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/matching/engineers/{id}/projects',
        summary: '技術者に対するおすすめ案件一覧',
        security: [['bearerAuth' => []]],
        tags: ['Matching'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '技術者ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'スコア付き案件一覧'),
            new OA\Response(response: 404, description: '技術者が見つかりません'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function recommendProjects(int $engineerId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $engineer = Engineer::with(['engineerSkills.skill', 'profile'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($engineerId);

        $recommendations = $this->matchingService->recommendProjects($engineer);

        return response()->json([
            'data' => $recommendations->map(fn($item) => [
                'project_id'               => $item['project']->id,
                'project_title'            => $item['project']->title,
                'unit_price_min'           => $item['project']->unit_price_min,
                'unit_price_max'           => $item['project']->unit_price_max,
                'work_style'               => $item['project']->work_style,
                'start_date'               => $item['project']->start_date,
                'score'                    => $item['score'],
                'score_badge'              => $this->scoreBadge($item['score']),
                'skill_match_score'        => $item['skill_match_score'],
                'price_match_score'        => $item['price_match_score'],
                'location_match_score'     => $item['location_match_score'],
                'availability_match_score' => $item['availability_match_score'],
                'required_skills'          => $item['project']->requiredSkills->map(fn($rs) => [
                    'name'                 => $rs->skill?->name,
                    'is_required'          => $rs->is_required,
                    'min_experience_years' => $rs->min_experience_years,
                ])->values(),
            ]),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/matching/projects/{projectId}/engineers/{engineerId}',
        summary: '案件×技術者マッチングスコア詳細',
        security: [['bearerAuth' => []]],
        tags: ['Matching'],
        parameters: [
            new OA\Parameter(name: 'projectId', in: 'path', required: true, description: '案件ID', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'engineerId', in: 'path', required: true, description: '技術者ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'スコア詳細とAI説明文'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function scoreDetail(int $projectId, int $engineerId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $project  = PublicProject::with(['requiredSkills.skill'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($projectId);

        $engineer = Engineer::with(['engineerSkills.skill', 'profile'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($engineerId);

        $scores = $this->matchingService->calculate($project, $engineer);
        $explanation = $this->matchingService->explainScore($project, $engineer, $scores);

        return response()->json([
            'data' => array_merge($scores, ['explanation' => $explanation]),
        ]);
    }

    /** スコアに応じた色バッジを返す（🟢70以上 / 🟡45以上 / ⚫それ以下） */
    private function scoreBadge(int $score): string
    {
        if ($score >= 70) return '🟢';
        if ($score >= 45) return '🟡';
        return '⚫';
    }

    #[OA\Get(
        path: '/api/v1/matching/skills',
        summary: 'スキルマスタ一覧取得',
        security: [['bearerAuth' => []]],
        tags: ['Matching'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'query', required: false, description: 'カテゴリ', schema: new OA\Schema(type: 'string', enum: ['language', 'framework', 'database', 'infrastructure', 'other'])),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'スキル名で検索', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'スキル一覧'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function skills(Request $request): JsonResponse
    {
        $query = Skill::query();

        if ($category = $request->get('category')) {
            $query->where('category', $category);
        }

        if ($search = $request->get('search')) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        return response()->json([
            'data' => $query->orderBy('category')->orderBy('name')->get(),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/matching/skills',
        summary: 'スキル登録（管理用）',
        security: [['bearerAuth' => []]],
        tags: ['Matching'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Laravel'),
                    new OA\Property(property: 'category', type: 'string', enum: ['language', 'framework', 'database', 'infrastructure', 'other']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: '登録成功'),
            new OA\Response(response: 422, description: 'バリデーションエラー'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function storeSkill(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name'     => 'required|string|max:100|unique:skills,name',
            'category' => 'nullable|in:language,framework,database,infrastructure,other',
        ]);

        $skill = Skill::create($v);

        return response()->json(['data' => $skill], 201);
    }
}
