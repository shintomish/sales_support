<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Engineer;
use OpenApi\Attributes as OA;
use App\Models\EngineerProfile;
use App\Models\EngineerSkill;
use App\Models\Skill;
use App\Services\ClaudeService;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;

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
            'affiliation_email'       => $e->affiliation_email,
            'age'                     => $e->age,
            'gender'                  => $e->gender,
            'nationality'             => $e->nationality,
            'nearest_station'         => $e->nearest_station,
            'affiliation_type'        => $e->affiliation_type,
            'engineer_mail_source_id' => $e->engineer_mail_source_id,
            'profile' => $p ? [
                'desired_unit_price_min' => $p->desired_unit_price_min,
                'desired_unit_price_max' => $p->desired_unit_price_max,
                'available_from'         => $p->available_from?->format('Y-m-d'),
                'availability_status'    => $p->availability_status,
                'current_project'        => $p->current_project,
                'current_customer'       => $p->current_customer,
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

    #[OA\Get(
        path: '/api/v1/engineers',
        summary: '技術者一覧取得',
        security: [['bearerAuth' => []]],
        tags: ['Engineers'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: '氏名・所属で検索', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'skill_id', in: 'query', required: false, description: 'スキルIDで絞り込み', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'work_style', in: 'query', required: false, description: '勤務形態', schema: new OA\Schema(type: 'string', enum: ['remote', 'office', 'hybrid'])),
            new OA\Parameter(name: 'available_only', in: 'query', required: false, description: '稼働可能のみ', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
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

        if ($source = $request->get('source')) {
            match ($source) {
                'self' => $query->where('affiliation_type', 'self'),
                'bp'   => $query->where('affiliation_type', '!=', 'self')->whereNull('engineer_mail_source_id'),
                'mail' => $query->whereNotNull('engineer_mail_source_id'),
                default => null,
            };
        }

        // 稼働可能日ソート: サブクエリで取得（JOIN は tenant_id 曖昧エラーになるため）
        // addSelect() は $columns=null 時に * を失うため、先に engineers.* を明示する
        if ($request->get('sort_by') === 'available_from') {
            $query->select('engineers.*', DB::raw(
                '(SELECT available_from FROM engineer_profiles WHERE engineer_profiles.engineer_id = engineers.id LIMIT 1) AS profile_available_from'
            ));
        }
        $paginated = $query->orderBy(...$this->resolveSort($request, [
            'name'            => 'engineers.name',
            'affiliation'     => 'engineers.affiliation',
            'available_from'  => 'profile_available_from',
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

    #[OA\Get(
        path: '/api/v1/engineers/{id}',
        summary: '技術者詳細取得',
        security: [['bearerAuth' => []]],
        tags: ['Engineers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '技術者ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 404, description: '見つかりません'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $engineer = Engineer::with(['profile', 'engineerSkills.skill'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json(['data' => $this->formatEngineer($engineer)]);
    }

    #[OA\Post(
        path: '/api/v1/engineers',
        summary: '技術者登録',
        security: [['bearerAuth' => []]],
        tags: ['Engineers'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: '鈴木 一郎'),
                    new OA\Property(property: 'name_kana', type: 'string', example: 'スズキ イチロウ'),
                    new OA\Property(property: 'email', type: 'string', example: 'suzuki@example.com'),
                    new OA\Property(property: 'affiliation', type: 'string', example: '株式会社サンプル'),
                    new OA\Property(property: 'affiliation_email', type: 'string', example: 'info@sample.co.jp'),
                    new OA\Property(property: 'affiliation_type', type: 'string', enum: ['self', 'first_sub', 'bp', 'bp_member', 'contract', 'freelance', 'joining', 'hiring']),
                    new OA\Property(property: 'work_style', type: 'string', enum: ['remote', 'office', 'hybrid']),
                    new OA\Property(property: 'available_from', type: 'string', format: 'date'),
                    new OA\Property(property: 'availability_status', type: 'string', enum: ['available', 'working', 'scheduled']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: '登録成功'),
            new OA\Response(response: 422, description: 'バリデーションエラー'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
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
            'affiliation_email'       => 'nullable|email|max:300',
            'age'                     => 'nullable|integer|min:18|max:80',
            'gender'                  => 'nullable|in:male,female,other,unanswered',
            'nationality'             => 'nullable|string|max:100',
            'nearest_station'         => 'nullable|string|max:100',
            'affiliation_type'        => 'nullable|in:self,first_sub,bp,bp_member,contract,freelance,joining,hiring',
            'engineer_mail_source_id' => 'nullable|integer|exists:engineer_mail_sources,id',
            // プロフィール
            'desired_unit_price_min'  => 'nullable|numeric|min:0',
            'desired_unit_price_max'  => 'nullable|numeric|min:0',
            'available_from'          => 'nullable|date',
            'availability_status'     => 'nullable|in:available,working,scheduled',
            'current_project'         => 'nullable|string|max:200',
            'current_customer'        => 'nullable|string|max:200',
            'past_client_count'       => 'nullable|integer|min:0',
            'work_style'              => 'nullable|in:remote,office,hybrid',
            'preferred_location'      => 'nullable|string|max:100',
            'self_introduction'       => 'nullable|string',
            'github_url'              => 'nullable|url|max:200',
            'portfolio_url'           => 'nullable|url|max:200',
            'resume_file_path'        => 'nullable|string|max:500',
            'is_public'               => 'nullable|boolean',
            // スキル
            'skills'                  => 'nullable|array',
            'skills.*.skill_id'       => 'required_with:skills|integer|exists:skills,id',
            'skills.*.experience_years' => 'nullable|numeric|min:0|max:50',
            'skills.*.proficiency_level' => 'nullable|integer|between:1,5',
        ]);

        \Log::info('[EngineerStore] skills received', ['count' => count($v['skills'] ?? []), 'skills' => $v['skills'] ?? []]);

        $engineer = DB::transaction(function () use ($v, $tenantId) {
            $engineer = Engineer::create([
                'tenant_id'           => $tenantId,
                'name'                => $v['name'],
                'name_kana'           => $v['name_kana'] ?? null,
                'email'               => $v['email'] ?? null,
                'phone'               => $v['phone'] ?? null,
                'affiliation'         => $v['affiliation'] ?? null,
                'affiliation_contact' => $v['affiliation_contact'] ?? null,
                'affiliation_email'   => $v['affiliation_email'] ?? null,
                'age'                 => $v['age'] ?? null,
                'gender'              => $v['gender'] ?? null,
                'nationality'         => $v['nationality'] ?? null,
                'nearest_station'     => $v['nearest_station'] ?? null,
                'affiliation_type'    => $v['affiliation_type'] ?? null,
                'engineer_mail_source_id' => $v['engineer_mail_source_id'] ?? null,
            ]);

            EngineerProfile::create([
                'tenant_id'              => $tenantId,
                'engineer_id'            => $engineer->id,
                'desired_unit_price_min' => $v['desired_unit_price_min'] ?? null,
                'desired_unit_price_max' => $v['desired_unit_price_max'] ?? null,
                'available_from'         => $v['available_from'] ?? null,
                'availability_status'    => $v['availability_status'] ?? 'available',
                'current_project'        => $v['current_project'] ?? null,
                'current_customer'       => $v['current_customer'] ?? null,
                'past_client_count'      => $v['past_client_count'] ?? null,
                'work_style'             => $v['work_style'] ?? null,
                'preferred_location'     => $v['preferred_location'] ?? null,
                'self_introduction'      => $v['self_introduction'] ?? null,
                'github_url'             => $v['github_url'] ?? null,
                'portfolio_url'          => $v['portfolio_url'] ?? null,
                'resume_file_path'       => $v['resume_file_path'] ?? null,
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

    #[OA\Put(
        path: '/api/v1/engineers/{id}',
        summary: '技術者更新',
        security: [['bearerAuth' => []]],
        tags: ['Engineers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '技術者ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: '更新成功'),
            new OA\Response(response: 422, description: 'バリデーションエラー'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
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
            'affiliation_email'       => 'nullable|email|max:300',
            'age'                     => 'nullable|integer|min:18|max:80',
            'gender'                  => 'nullable|in:male,female,other,unanswered',
            'nationality'             => 'nullable|string|max:100',
            'nearest_station'         => 'nullable|string|max:100',
            'affiliation_type'        => 'nullable|in:self,first_sub,bp,bp_member,contract,freelance,joining,hiring',
            'desired_unit_price_min'  => 'nullable|numeric|min:0',
            'desired_unit_price_max'  => 'nullable|numeric|min:0',
            'available_from'          => 'nullable|date',
            'availability_status'     => 'nullable|in:available,working,scheduled',
            'current_project'         => 'nullable|string|max:200',
            'current_customer'        => 'nullable|string|max:200',
            'past_client_count'       => 'nullable|integer|min:0',
            'work_style'              => 'nullable|in:remote,office,hybrid',
            'preferred_location'      => 'nullable|string|max:100',
            'self_introduction'       => 'nullable|string',
            'github_url'              => 'nullable|url|max:200',
            'portfolio_url'           => 'nullable|url|max:200',
            'resume_file_path'        => 'nullable|string|max:500',
            'is_public'               => 'nullable|boolean',
            'skills'                  => 'nullable|array',
            'skills.*.skill_id'       => 'required_with:skills|integer|exists:skills,id',
            'skills.*.experience_years' => 'nullable|numeric|min:0|max:50',
            'skills.*.proficiency_level' => 'nullable|integer|between:1,5',
        ]);

        \Log::info('[EngineerUpdate] skills received', ['engineer_id' => $engineer->id, 'has_skills_key' => array_key_exists('skills', $v), 'count' => count($v['skills'] ?? [])]);

        DB::transaction(function () use ($v, $engineer, $tenantId) {
            $engineerFields = array_filter([
                'name'                => $v['name'] ?? null,
                'name_kana'           => $v['name_kana'] ?? null,
                'email'               => $v['email'] ?? null,
                'phone'               => $v['phone'] ?? null,
                'affiliation'         => $v['affiliation'] ?? null,
                'affiliation_contact' => $v['affiliation_contact'] ?? null,
                'affiliation_email'   => $v['affiliation_email'] ?? null,
                'age'                 => $v['age'] ?? null,
                'gender'              => $v['gender'] ?? null,
                'nationality'         => $v['nationality'] ?? null,
                'nearest_station'     => $v['nearest_station'] ?? null,
                'affiliation_type'    => $v['affiliation_type'] ?? null,
            ], fn($val) => $val !== null);
            $engineer->update($engineerFields);

            $profileFields = array_filter([
                'desired_unit_price_min' => $v['desired_unit_price_min'] ?? null,
                'desired_unit_price_max' => $v['desired_unit_price_max'] ?? null,
                'available_from'         => $v['available_from'] ?? null,
                'availability_status'    => $v['availability_status'] ?? null,
                'current_project'        => $v['current_project'] ?? null,
                'current_customer'       => $v['current_customer'] ?? null,
                'past_client_count'      => $v['past_client_count'] ?? null,
                'work_style'             => $v['work_style'] ?? null,
                'preferred_location'     => $v['preferred_location'] ?? null,
                'self_introduction'      => $v['self_introduction'] ?? null,
                'github_url'             => $v['github_url'] ?? null,
                'portfolio_url'          => $v['portfolio_url'] ?? null,
                'resume_file_path'       => $v['resume_file_path'] ?? null,
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

    #[OA\Delete(
        path: '/api/v1/engineers/{id}',
        summary: '技術者削除',
        security: [['bearerAuth' => []]],
        tags: ['Engineers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '技術者ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: '削除成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function destroy(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        Engineer::where('tenant_id', $tenantId)->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/engineers/parse-skill-sheet',
        summary: 'スキルシート解析（Claude API）',
        security: [['bearerAuth' => []]],
        tags: ['Engineers'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'PDF/Excel/Wordファイル（最大10MB）'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: '解析成功（技術者情報・スキル一覧を返す）'),
            new OA\Response(response: 422, description: 'ファイル解析失敗'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function parseSkillSheet(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,application/vnd.ms-excel.sheet.macroEnabled.12,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/msword',
        ]);

        $file = $request->file('file');

        // テキスト抽出
        $text = $this->extractTextFromFile($file);
        if (empty(trim($text))) {
            return response()->json(['message' => 'ファイルからテキストを抽出できませんでした'], 422);
        }

        // Supabase Storage に保存
        $storage = app(SupabaseStorageService::class);
        $fileUrl = $storage->upload($file, 'skill-sheets');

        // Claude API で解析
        $claude    = app(ClaudeService::class);
        $extracted = $claude->extractSkillSheetInfo(mb_substr($text, 0, 8000));

        // スキル名 → skill_id 解決（なければ新規作成）
        $skills = [];
        foreach ($extracted['skills'] ?? [] as $s) {
            if (empty($s['name'])) continue;
            $skill = Skill::firstOrCreate(
                ['name' => $s['name']],
                ['category' => 'other']
            );
            $skills[] = [
                'skill_id'          => $skill->id,
                'skill_name'        => $skill->name,
                'category'          => $skill->category,
                'experience_years'  => $s['experience_years'] ?? 0,
                'proficiency_level' => 3,
            ];
        }

        unset($extracted['skills']);

        return response()->json([
            'extracted' => $extracted,
            'skills'    => $skills,
            'file_url'  => $fileUrl,
        ]);
    }

    private function extractTextFromFile(\Illuminate\Http\UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'pdf') {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($file->getPathname());
            return $pdf->getText();
        }

        if (in_array($ext, ['xlsx', 'xls', 'xlsm'])) {
            $spreadsheet = SpreadsheetIOFactory::load($file->getPathname());
            $text = '';
            // 最初の2シートまで抽出（基本情報シートを優先）
            foreach (array_slice($spreadsheet->getAllSheets(), 0, 2) as $sheet) {
                $text .= '=== ' . $sheet->getTitle() . " ===\n";
                foreach ($sheet->getRowIterator() as $row) {
                    $rowCells = [];
                    $cellIter = $row->getCellIterator();
                    $cellIter->setIterateOnlyExistingCells(true);
                    foreach ($cellIter as $cell) {
                        $val = trim($cell->getFormattedValue());
                        if ($val !== '') $rowCells[] = $val;
                    }
                    if (!empty($rowCells)) $text .= implode("\t", $rowCells) . "\n";
                }
            }
            return $text;
        }

        if (in_array($ext, ['docx', 'doc'])) {
            $phpWord = WordIOFactory::load($file->getPathname());
            $text = '';
            foreach ($phpWord->getSections() as $section) {
                $text .= $this->extractWordSectionText($section);
            }
            return $text;
        }

        return '';
    }

    private function extractWordSectionText(\PhpOffice\PhpWord\Element\Section $section): string
    {
        $text = '';
        foreach ($section->getElements() as $element) {
            $text .= $this->extractWordElementText($element);
        }
        return $text;
    }

    private function extractWordElementText(object $element): string
    {
        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun
            || $element instanceof \PhpOffice\PhpWord\Element\Paragraph) {
            $text = '';
            foreach ($element->getElements() as $child) {
                $text .= $this->extractWordElementText($child);
            }
            return $text . "\n";
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
            return $element->getText();
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
            $text = '';
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    foreach ($cell->getElements() as $cellElement) {
                        $text .= $this->extractWordElementText($cellElement) . ' ';
                    }
                }
                $text .= "\n";
            }
            return $text;
        }
        return '';
    }
}
