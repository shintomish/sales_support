<?php

namespace App\Services;

use App\Models\Engineer;
use App\Models\EngineerMailSource;
use App\Models\ProjectMailSource;
use App\Models\RequirementMatchResult;
use Illuminate\Support\Facades\Log;

/**
 * 案件要件 × 候補技術者 の対照表生成サービス (docs/480 §4)
 *
 * 流れ:
 *  1. extractRequirements(PMS) — PMS.ai_requirements が空なら Claude Stage 1 で抽出・永続化
 *  2. judgeMatches(PMS, EMS|Engineer) — RequirementMatchResult を取得 or 生成
 *  3. regenerate(PMS, EMS|Engineer) — 強制再生成 (営業の編集消失リスクは Controller で確認モーダル)
 */
class RequirementMatchingService
{
    public function __construct(
        private ClaudeService $claude,
    ) {}

    /**
     * Stage 1: PMS から要件を抽出 (キャッシュあり)。
     *
     * @return array Stage 1 出力の requirements 配列
     */
    public function extractRequirements(ProjectMailSource $pms, bool $forceRefresh = false): array
    {
        if (!$forceRefresh && !empty($pms->ai_requirements)) {
            return $pms->ai_requirements;
        }

        $pms->loadMissing('email');
        $body = $pms->email?->body_text ?? $pms->email?->body_html ?? '';
        if ($body === '') {
            throw new \RuntimeException("PMS {$pms->id} の本文が空のため要件抽出できません");
        }

        $subject = $pms->email?->subject ?? $pms->title ?? '';
        $result = $this->claude->extractRequirements($subject, $body);

        $requirements = $result['requirements'] ?? [];
        $pms->update([
            'ai_requirements'              => $requirements,
            'ai_requirements_generated_at' => now(),
        ]);

        Log::info('[RequirementMatching] Stage1 extracted', [
            'pms_id'         => $pms->id,
            'count'          => count($requirements),
            'usage'          => $result['_usage'] ?? [],
        ]);

        return $requirements;
    }

    /**
     * Stage 2: PMS × EMS|Engineer の対照表を取得。キャッシュ優先・無ければ生成。
     */
    public function getOrGenerate(
        ProjectMailSource $pms,
        EngineerMailSource|Engineer $candidate
    ): RequirementMatchResult {
        $existing = $this->findExisting($pms, $candidate);
        if ($existing) {
            return $existing;
        }
        return $this->generate($pms, $candidate);
    }

    /**
     * Stage 2 強制再生成。既存レコードを soft-delete してから新規作成。
     * 営業の手動上書き (edited_at) がある場合は呼び元で確認を取る前提。
     */
    public function regenerate(
        ProjectMailSource $pms,
        EngineerMailSource|Engineer $candidate
    ): RequirementMatchResult {
        $existing = $this->findExisting($pms, $candidate);
        if ($existing) {
            $existing->delete();
        }
        return $this->generate($pms, $candidate);
    }

    private function findExisting(
        ProjectMailSource $pms,
        EngineerMailSource|Engineer $candidate
    ): ?RequirementMatchResult {
        $q = RequirementMatchResult::where('tenant_id', $pms->tenant_id)
            ->where('project_mail_source_id', $pms->id);

        if ($candidate instanceof EngineerMailSource) {
            $q->where('engineer_mail_source_id', $candidate->id);
        } else {
            $q->where('engineer_id', $candidate->id);
        }

        return $q->first();
    }

    private function generate(
        ProjectMailSource $pms,
        EngineerMailSource|Engineer $candidate
    ): RequirementMatchResult {
        // Stage 1 (cached or fresh)
        $requirements = $this->extractRequirements($pms);

        // Stage 2 入力データの組立
        [$engineerData, $bodyText, $skillSheetText] = $this->buildCandidatePayload($candidate);

        $result = $this->claude->judgeRequirementMatches(
            $requirements,
            $engineerData,
            $bodyText,
            $skillSheetText,
        );

        $usage = $result['_usage'] ?? [];

        $record = RequirementMatchResult::create([
            'tenant_id'               => $pms->tenant_id,
            'project_mail_source_id'  => $pms->id,
            'engineer_mail_source_id' => $candidate instanceof EngineerMailSource ? $candidate->id : null,
            'engineer_id'             => $candidate instanceof Engineer ? $candidate->id : null,
            'requirements_json'       => $requirements,
            'matches_json'            => $result['matches'] ?? [],
            'model'                   => (string) config('services.anthropic.model'),
            'input_tokens'            => $usage['input_tokens'] ?? null,
            'output_tokens'           => $usage['output_tokens'] ?? null,
            'cache_read_tokens'       => $usage['cache_read_input_tokens'] ?? null,
            'cache_write_tokens'      => $usage['cache_creation_input_tokens'] ?? null,
            'generated_at'            => now(),
        ]);

        Log::info('[RequirementMatching] Stage2 generated', [
            'pms_id'      => $pms->id,
            'ems_id'      => $candidate instanceof EngineerMailSource ? $candidate->id : null,
            'engineer_id' => $candidate instanceof Engineer ? $candidate->id : null,
            'match_count' => count($result['matches'] ?? []),
            'usage'       => $usage,
        ]);

        return $record;
    }

    /** Stage 2 プロンプトに渡す候補データを組み立てる。 */
    private function buildCandidatePayload(
        EngineerMailSource|Engineer $candidate
    ): array {
        if ($candidate instanceof EngineerMailSource) {
            $candidate->loadMissing('email');
            $data = [
                'name'             => $candidate->name,
                'age'              => $candidate->age,
                'affiliation'      => $candidate->affiliation,
                'affiliation_type' => $candidate->affiliation_type,
                'skills'           => $candidate->skills,
                'available_from'   => $candidate->available_from,
                'nearest_station'  => $candidate->nearest_station,
                'unit_price_min'   => $candidate->unit_price_min,
                'unit_price_max'   => $candidate->unit_price_max,
            ];
            return [$data, $candidate->email?->body_text, $candidate->parsed_skill_sheet_text];
        }

        // Engineer (登録済): プロフィール + skills を集約
        $candidate->loadMissing(['skills.skill', 'profile']);
        $data = [
            'name'              => $candidate->name,
            'affiliation_type'  => $candidate->affiliation_type,
            'nearest_station'   => $candidate->nearest_station,
            'affiliation_email' => $candidate->affiliation_email,
            'skills'            => $candidate->skills->map(fn($es) => $es->skill?->name)->filter()->values()->all(),
            'profile_pr'        => $candidate->profile?->self_pr ?? null,
        ];
        return [$data, null, null];
    }
}
