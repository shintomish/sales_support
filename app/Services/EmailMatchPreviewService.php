<?php

namespace App\Services;

use App\Models\Email;
use App\Models\Engineer;
use App\Models\PublicProject;

class EmailMatchPreviewService
{
    private const SKILL_WEIGHT    = 0.50;
    private const PRICE_WEIGHT    = 0.30;
    private const WORKSTYLE_WEIGHT = 0.20;

    /**
     * メールの抽出データを元に上位5件の候補を返す
     * @return array{ category: string, matches: array }
     */
    public function preview(Email $email, int $limit = 5): array
    {
        $result = $email->extracted_data['result'] ?? [];
        if (empty($result)) {
            return ['category' => $email->category, 'matches' => []];
        }

        $matches = $email->category === 'engineer'
            ? $this->matchProjects($result, $limit)
            : $this->matchEngineers($result, $limit);

        return [
            'category' => $email->category,
            'matches'  => $matches,
        ];
    }

    // ── 技術者メール → 案件マッチング ────────────────────────

    private function matchProjects(array $result, int $limit): array
    {
        $extractedSkills  = array_map('mb_strtolower', $result['skills'] ?? []);
        $priceMin         = $result['desired_unit_price_min'] ?? null;
        $priceMax         = $result['desired_unit_price_max'] ?? null;
        $workStyle        = $result['work_style'] ?? null;

        $projects = PublicProject::with(['requiredSkills.skill'])
            ->where('status', 'open')
            ->get();

        $scored = $projects->map(function (PublicProject $p) use ($extractedSkills, $priceMin, $priceMax, $workStyle) {
            $projectSkills  = $p->requiredSkills->map(fn($rs) => mb_strtolower($rs->skill->name))->toArray();
            $skillScore     = $this->calcSkillScore($extractedSkills, $projectSkills);
            $priceScore     = $this->calcPriceScoreForProject($p, $priceMin, $priceMax);
            $workStyleScore = $this->calcWorkStyleScore($p->work_style, $workStyle);

            $score = (int) round(
                ($skillScore     * self::SKILL_WEIGHT +
                 $priceScore     * self::PRICE_WEIGHT +
                 $workStyleScore * self::WORKSTYLE_WEIGHT) * 100
            );

            $matchedSkills = array_values(array_intersect($extractedSkills, $projectSkills));

            return [
                'id'             => $p->id,
                'title'          => $p->title,
                'score'          => $score,
                'score_badge'    => $this->scoreBadge($score),
                'skill_matches'  => $matchedSkills,
                'unit_price_min' => $p->unit_price_min,
                'unit_price_max' => $p->unit_price_max,
                'work_style'     => $p->work_style,
                'work_location'  => $p->work_location,
                'start_date'     => $p->start_date?->format('Y-m-d'),
            ];
        })
        ->sortByDesc('score')
        ->take($limit)
        ->values()
        ->toArray();

        return $scored;
    }

    // ── 案件メール → 技術者マッチング ────────────────────────

    private function matchEngineers(array $result, int $limit): array
    {
        $extractedSkills = array_map('mb_strtolower', $result['skills'] ?? []);
        $priceMin        = $result['unit_price_min'] ?? null;
        $priceMax        = $result['unit_price_max'] ?? null;
        $workStyle       = $result['work_style'] ?? null;

        $engineers = Engineer::with(['engineerSkills.skill', 'profile'])
            ->whereHas('profile', fn($q) => $q->where('is_public', true)->orWhere('is_public', false))
            ->get();

        $scored = $engineers->map(function (Engineer $e) use ($extractedSkills, $priceMin, $priceMax, $workStyle) {
            $engineerSkills = $e->engineerSkills->map(fn($es) => mb_strtolower($es->skill->name))->toArray();
            $skillScore     = $this->calcSkillScore($extractedSkills, $engineerSkills);
            $priceScore     = $this->calcPriceScoreForEngineer($e->profile, $priceMin, $priceMax);
            $workStyleScore = $this->calcWorkStyleScore($e->profile?->work_style, $workStyle);

            $score = (int) round(
                ($skillScore     * self::SKILL_WEIGHT +
                 $priceScore     * self::PRICE_WEIGHT +
                 $workStyleScore * self::WORKSTYLE_WEIGHT) * 100
            );

            $matchedSkills = array_values(array_intersect($extractedSkills, $engineerSkills));

            return [
                'id'               => $e->id,
                'name'             => $e->name,
                'score'            => $score,
                'score_badge'      => $this->scoreBadge($score),
                'skill_matches'    => $matchedSkills,
                'desired_price_min'=> $e->profile?->desired_unit_price_min,
                'desired_price_max'=> $e->profile?->desired_unit_price_max,
                'work_style'       => $e->profile?->work_style,
                'available_from'   => $e->profile?->available_from?->format('Y-m-d'),
                'affiliation'      => $e->affiliation,
            ];
        })
        ->sortByDesc('score')
        ->take($limit)
        ->values()
        ->toArray();

        return $scored;
    }

    // ── バッジ ───────────────────────────────────────────────

    /** スコアに応じた色バッジを返す（🟢70以上 / 🟡45以上 / ⚫それ以下） */
    private function scoreBadge(int $score): string
    {
        if ($score >= 70) return '🟢';
        if ($score >= 45) return '🟡';
        return '⚫';
    }

    // ── スコア計算 ────────────────────────────────────────────

    /** スキル一致率（0〜1） */
    private function calcSkillScore(array $sourceSkills, array $targetSkills): float
    {
        if (empty($sourceSkills) || empty($targetSkills)) {
            return 0.0;
        }
        $matched = count(array_intersect($sourceSkills, $targetSkills));
        return $matched / max(count($sourceSkills), count($targetSkills));
    }

    /** 案件単価 vs 技術者希望単価（0〜1） */
    private function calcPriceScoreForEngineer($profile, ?float $projectMin, ?float $projectMax): float
    {
        if (!$profile || (!$projectMin && !$projectMax)) return 0.5;
        $engMin = $profile->desired_unit_price_min;
        $engMax = $profile->desired_unit_price_max ?? $engMin;
        if (!$engMin) return 0.5;

        $pMin = $projectMin ?? $projectMax;
        $pMax = $projectMax ?? $projectMin;

        // 範囲が重なっているか
        if ($pMax >= $engMin && $pMin <= $engMax) return 1.0;
        // 近い場合は部分点
        $gap = min(abs($pMax - $engMin), abs($pMin - $engMax));
        return max(0.0, 1.0 - ($gap / 20));
    }

    /** 技術者希望単価 vs 案件単価（0〜1） */
    private function calcPriceScoreForProject(PublicProject $project, ?float $engMin, ?float $engMax): float
    {
        if (!$project->unit_price_min && !$project->unit_price_max) return 0.5;
        if (!$engMin && !$engMax) return 0.5;

        $pMin = $project->unit_price_min ?? $project->unit_price_max;
        $pMax = $project->unit_price_max ?? $project->unit_price_min;
        $eMin = $engMin ?? $engMax;
        $eMax = $engMax ?? $engMin;

        if ($pMax >= $eMin && $pMin <= $eMax) return 1.0;
        $gap = min(abs($pMax - $eMin), abs($pMin - $eMax));
        return max(0.0, 1.0 - ($gap / 20));
    }

    /** 勤務形態一致（0〜1） */
    private function calcWorkStyleScore(?string $a, ?string $b): float
    {
        if (!$a || !$b) return 0.5;
        if ($a === $b) return 1.0;
        if ($a === 'hybrid' || $b === 'hybrid') return 0.6;
        return 0.0;
    }
}
