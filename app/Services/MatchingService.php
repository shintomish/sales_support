<?php

namespace App\Services;

use App\Models\Engineer;
use App\Models\PublicProject;
use App\Models\MatchingScore;

class MatchingService
{
    // マッチングスコアの重み
    private array $weights = [
        'skill'        => 0.50,
        'price'        => 0.25,
        'location'     => 0.15,
        'availability' => 0.10,
    ];

    public function __construct(private ClaudeService $claude) {}

    /** 単発スコアリング (in-memory). DB キャッシュ更新は `$persist=true` のときだけ. */
    public function calculate(PublicProject $project, Engineer $engineer, bool $persist = false): array
    {
        $project->loadMissing(['requiredSkills.skill']);
        $engineer->loadMissing(['engineerSkills.skill', 'profile']);

        $factors = [
            'skill_match_score'        => $this->calcSkillScore($project, $engineer),
            'price_match_score'        => $this->calcPriceScore($project, $engineer),
            'location_match_score'     => $this->calcLocationScore($project, $engineer),
            'availability_match_score' => $this->calcAvailabilityScore($project, $engineer),
        ];

        $score = round(
            $factors['skill_match_score']        * $this->weights['skill'] +
            $factors['price_match_score']        * $this->weights['price'] +
            $factors['location_match_score']     * $this->weights['location'] +
            $factors['availability_match_score'] * $this->weights['availability'],
            2
        );

        // GET 経路 (recommendEngineers / recommendProjects) からは書込しない (docs/730 §Medium #8)。
        // 一覧表示のたびに候補件数分の SELECT+UPDATE が走っていたのを抑制。
        if ($persist) {
            MatchingScore::updateOrCreate(
                ['project_id' => $project->id, 'engineer_id' => $engineer->id],
                array_merge($factors, [
                    'tenant_id'     => $project->tenant_id,
                    'score'         => $score,
                    'calculated_at' => now(),
                ])
            );
        }

        return array_merge(['score' => $score], $factors);
    }

    /**
     * 案件に対して上位N人の技術者をレコメンドする（スコア再計算後に返す）。
     *
     * CLAUDE.md「確定済み設計判断」より、以下の技術者は除外：
     * - 希望単価未記載（no_unit_price）
     * - 希望単価が 35万/月未満（unit_price_too_low）
     * - 案件 unit_price_max が non-null の場合、技術者希望 > 案件上限の組み合わせ
     */
    public function recommendEngineers(PublicProject $project, int $limit = 10): \Illuminate\Support\Collection
    {
        $projectMax = $project->unit_price_max;

        // 安全弁: テナント全 Engineer をメモリ展開してから PHP 側 sort するため
        // 大規模テナントで OOM 化するリスクがあった (docs/730 §Medium #9)。
        // 中長期では事前 DB スコアリング + LIMIT が望ましいが、まず HARD_LIMIT で抑える。
        $HARD_LIMIT = 500;
        $engineers = Engineer::with(['engineerSkills.skill', 'profile'])
            ->where('tenant_id', $project->tenant_id)
            ->whereHas('profile', function ($q) use ($projectMax) {
                $q->where('is_public', true)
                  ->whereNotNull('desired_unit_price_max')
                  ->where('desired_unit_price_max', '>=', 35);
                if ($projectMax !== null) {
                    $q->where('desired_unit_price_max', '<=', $projectMax);
                }
            })
            ->limit($HARD_LIMIT)
            ->get();

        return $engineers
            ->map(fn(Engineer $e) => array_merge(
                ['engineer' => $e],
                $this->calculate($project, $e)
            ))
            ->sortByDesc('score')
            ->values()
            ->take($limit);
    }

    /**
     * 技術者に対して上位N件の案件をレコメンドする。
     *
     * 技術者本人の希望単価が null または 35万未満の場合は本番マッチング不能のため空コレクションを返す。
     * 案件側 unit_price_max が null の場合は除外せずスコア中間評価で扱う。
     */
    public function recommendProjects(Engineer $engineer, int $limit = 10): \Illuminate\Support\Collection
    {
        $eDesiredMax = $engineer->profile?->desired_unit_price_max;
        if ($eDesiredMax === null || $eDesiredMax < 35) {
            return collect();
        }

        $HARD_LIMIT = 500;
        $projects = PublicProject::with(['requiredSkills.skill'])
            ->where('tenant_id', $engineer->tenant_id)
            ->open()
            ->published()
            ->where(function ($q) use ($eDesiredMax) {
                $q->whereNull('unit_price_max')
                  ->orWhere('unit_price_max', '>=', $eDesiredMax);
            })
            ->limit($HARD_LIMIT)
            ->get();

        return $projects
            ->map(fn(PublicProject $p) => array_merge(
                ['project' => $p],
                $this->calculate($p, $engineer)
            ))
            ->sortByDesc('score')
            ->values()
            ->take($limit);
    }

    /**
     * マッチング理由をClaude APIで生成する
     */
    public function explainScore(PublicProject $project, Engineer $engineer, array $scores): string
    {
        $project->loadMissing(['requiredSkills.skill']);
        $engineer->loadMissing(['engineerSkills.skill', 'profile']);

        $requiredSkillNames = $project->requiredSkills
            ->map(fn($rs) => $rs->skill->name . ($rs->is_required ? '（必須）' : '（歓迎）'))
            ->join(', ');

        $engineerSkillNames = $engineer->engineerSkills
            ->map(fn($es) => "{$es->skill->name}({$es->experience_years}年)")
            ->join(', ');

        $prompt = <<<PROMPT
以下の案件と技術者のマッチング結果について、2-3文で簡潔に説明してください。

【案件】
- タイトル: {$project->title}
- 必須スキル: {$requiredSkillNames}
- 単価: {$project->unit_price_min}-{$project->unit_price_max}万円
- 勤務形態: {$project->work_style}
- 開始日: {$project->start_date}

【技術者】
- 保有スキル: {$engineerSkillNames}
- 希望単価: {$engineer->profile?->desired_unit_price_min}-{$engineer->profile?->desired_unit_price_max}万円
- 希望勤務形態: {$engineer->profile?->work_style}
- 稼働可能日: {$engineer->profile?->available_from}

【マッチングスコア】
- 総合: {$scores['score']}点
- スキル適合度: {$scores['skill_match_score']}点
- 単価適合度: {$scores['price_match_score']}点
- 勤務地適合度: {$scores['location_match_score']}点
- 稼働時期適合度: {$scores['availability_match_score']}点

なぜこのスコアになったか、強みと懸念点を含めて2-3文で説明してください。
PROMPT;

        try {
            $response = $this->claude->sendMessages([
                'model'      => config('services.anthropic.model'),
                'max_tokens' => 300,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ], ['timeout' => 30]);

            if ($response->failed()) {
                return '';
            }

            return $response->json('content.0.text', '');
        } catch (\Throwable) {
            return '';
        }
    }

    // ── スコア計算ロジック ────────────────────────────

    private function calcSkillScore(PublicProject $project, Engineer $engineer): float
    {
        $requiredSkills = $project->requiredSkills;
        if ($requiredSkills->isEmpty()) {
            return 100.0;
        }

        $engineerSkillMap = $engineer->engineerSkills->keyBy('skill_id');
        $totalWeight  = 0;
        $matchedScore = 0;

        foreach ($requiredSkills as $required) {
            $weight       = $required->is_required ? 2 : 1;
            $totalWeight += $weight;

            $engineerSkill = $engineerSkillMap->get($required->skill_id);
            if ($engineerSkill) {
                $minYears        = max($required->min_experience_years ?? 1, 0.1);
                $experienceScore = min($engineerSkill->experience_years / $minYears, 1.0);
                $matchedScore   += $weight * $experienceScore;
            }
        }

        return round(($matchedScore / $totalWeight) * 100, 2);
    }

    private function calcPriceScore(PublicProject $project, Engineer $engineer): float
    {
        $pMin = $project->unit_price_min;
        $pMax = $project->unit_price_max ?? $pMin;
        $eMin = $engineer->profile?->desired_unit_price_min;
        $eMax = $engineer->profile?->desired_unit_price_max ?? $eMin;

        if (!$pMin || !$eMin) {
            return 50.0; // 情報不足は中間値
        }

        $overlapMin = max($pMin, $eMin);
        $overlapMax = min($pMax, $eMax);

        if ($overlapMin > $overlapMax) {
            return 0.0;
        }

        $overlapRange  = $overlapMax - $overlapMin;
        $pRange        = max($pMax - $pMin, 1);
        $eRange        = max($eMax - $eMin, 1);
        $avgRange      = ($pRange + $eRange) / 2;

        return round(min(($overlapRange / $avgRange) * 100, 100), 2);
    }

    private function calcLocationScore(PublicProject $project, Engineer $engineer): float
    {
        $pStyle = $project->work_style;
        $eStyle = $engineer->profile?->work_style;

        if ($pStyle === 'remote' && $eStyle === 'remote') return 100.0;
        if ($pStyle === 'remote' || $eStyle === 'remote') return 90.0;
        if ($pStyle === 'hybrid' && $eStyle === 'hybrid') return 80.0;
        if ($pStyle === 'hybrid' || $eStyle === 'hybrid') return 60.0;

        $pLoc = $project->work_location ?? '';
        $eLoc = $engineer->profile?->preferred_location ?? '';

        if ($pLoc && $eLoc && (str_contains($pLoc, $eLoc) || str_contains($eLoc, $pLoc))) {
            return 70.0;
        }

        return 30.0;
    }

    private function calcAvailabilityScore(PublicProject $project, Engineer $engineer): float
    {
        if (!$project->start_date || !$engineer->profile?->available_from) {
            return 50.0;
        }

        $daysDiff = $project->start_date->diffInDays($engineer->profile->available_from, false);

        // 技術者の稼働可能日がプロジェクト開始日以前なら満点
        if ($daysDiff <= 0) return 100.0;

        // 1ヶ月以内の遅延
        if ($daysDiff <= 30) return 80.0;

        // 2ヶ月以内
        if ($daysDiff <= 60) return 60.0;

        return max(0, round(60 - ($daysDiff - 60) / 3, 2));
    }
}
