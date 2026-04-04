<?php

namespace App\Services;

use App\Models\Engineer;
use App\Models\ProjectMailSource;
use Illuminate\Support\Collection;

/**
 * 案件メール × 技術者 マッチングスコア計算エンジン
 *
 * 100点満点:
 *   ① 必須条件一致    40点
 *   ② スキル適合度    25点
 *   ③ 条件適合度      20点
 *   ④ 稼働・タイミング 10点
 *   ⑤ 商流・実績補正    5点
 */
class ProjectMailMatchingService
{
    /**
     * 案件メールに対してマッチする技術者を上位N件返す
     *
     * @return Collection<array{engineer: Engineer, score: int, breakdown: array, reasons: string[]}>
     */
    public function matchEngineers(ProjectMailSource $mail, int $limit = 20): Collection
    {
        $tenantId = $mail->tenant_id;

        $engineers = Engineer::with(['engineerSkills.skill', 'profile'])
            ->where('tenant_id', $tenantId)
            ->get();

        return $engineers
            ->map(fn(Engineer $e) => $this->score($mail, $e))
            ->filter(fn($r) => $r['score'] > 0)
            ->sortByDesc('score')
            ->values()
            ->take($limit);
    }

    /**
     * 案件メール × 技術者のスコアを計算する
     */
    public function score(ProjectMailSource $mail, Engineer $engineer): array
    {
        $breakdown = [];
        $reasons   = [];

        // ── ① 必須条件一致（40点）────────────────────────────
        [$s1, $r1, $excluded] = $this->scoreRequirements($mail, $engineer);
        $breakdown['requirements'] = $s1;
        $reasons = array_merge($reasons, $r1);

        if ($excluded) {
            return [
                'engineer'  => $engineer,
                'score'     => 0,
                'breakdown' => array_merge($breakdown, [
                    'skills'       => 0,
                    'conditions'   => 0,
                    'availability' => 0,
                    'track_record' => 0,
                ]),
                'reasons'   => $reasons,
            ];
        }

        // ── ② スキル適合度（25点）────────────────────────────
        [$s2, $r2] = $this->scoreSkills($mail, $engineer);
        $breakdown['skills'] = $s2;
        $reasons = array_merge($reasons, $r2);

        // ── ③ 条件適合度（20点）──────────────────────────────
        [$s3, $r3] = $this->scoreConditions($mail, $engineer);
        $breakdown['conditions'] = $s3;
        $reasons = array_merge($reasons, $r3);

        // ── ④ 稼働・タイミング（10点）────────────────────────
        [$s4, $r4] = $this->scoreAvailability($mail, $engineer);
        $breakdown['availability'] = $s4;
        $reasons = array_merge($reasons, $r4);

        // ── ⑤ 商流・実績補正（5点）───────────────────────────
        [$s5, $r5] = $this->scoreTrackRecord($engineer);
        $breakdown['track_record'] = $s5;
        $reasons = array_merge($reasons, $r5);

        $total = $s1 + $s2 + $s3 + $s4 + $s5;

        return [
            'engineer'  => $engineer,
            'score'     => $total,
            'breakdown' => $breakdown,
            'reasons'   => $reasons,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // ① 必須条件一致（40点）
    // ─────────────────────────────────────────────────────────

    private function scoreRequirements(ProjectMailSource $mail, Engineer $engineer): array
    {
        $score    = 0;
        $reasons  = [];
        $excluded = false;

        // 国籍（15点）
        $nationalityScore = $this->scoreNationality($mail, $engineer, $reasons, $excluded);
        if ($excluded) {
            return [0, array_merge($reasons, ['国籍条件不適合（除外）']), true];
        }
        $score += $nationalityScore;

        // 年齢制限（15点）
        $ageScore = $this->scoreAge($mail, $engineer, $reasons, $excluded);
        if ($excluded) {
            return [$score, array_merge($reasons, ['年齢制限不適合（除外）']), true];
        }
        $score += $ageScore;

        // 勤務形態（10点）
        $score += $this->scoreWorkStyleFit($mail, $engineer, $reasons);

        return [$score, $reasons, false];
    }

    private function scoreNationality(ProjectMailSource $mail, Engineer $engineer, array &$reasons, bool &$excluded): int
    {
        $nationalityOk = $mail->nationality_ok; // true=外国籍OK, false=日本人のみ, null=不明

        if ($nationalityOk === null) {
            return 8; // 情報不足: 中間点
        }

        if ($nationalityOk === true) {
            return 15; // 制限なし
        }

        // nationality_ok = false → 日本人のみ
        $engNationality = trim($engineer->nationality ?? '');
        if ($engNationality === '' || $engNationality === '日本' || $engNationality === '日本人') {
            $reasons[] = '国籍条件OK（日本人）';
            return 15;
        }

        // 外国籍の場合は足切り
        $excluded = true;
        return 0;
    }

    private function scoreAge(ProjectMailSource $mail, Engineer $engineer, array &$reasons, bool &$excluded): int
    {
        $ageLimit = trim($mail->age_limit ?? '');
        $engAge   = $engineer->age;

        if ($ageLimit === '' || $ageLimit === null) {
            return 8; // 制限なし: 中間点
        }

        if ($engAge === null) {
            return 5; // エンジニアの年齢不明
        }

        // "〜45歳" "45歳以下" "45歳未満" → max age
        if (preg_match('/(\d+)\s*歳?\s*(?:以下|まで|〜$|未満)/u', $ageLimit, $m) ||
            preg_match('/[〜～]\s*(\d+)\s*歳?/u', $ageLimit, $m)) {
            $maxAge = (int)$m[1];
            $limit  = str_contains($ageLimit, '未満') ? $maxAge - 1 : $maxAge;
            if ($engAge <= $limit) {
                $reasons[] = "年齢条件OK（{$engAge}歳/{$ageLimit}）";
                return 15;
            }
            $excluded = true;
            return 0;
        }

        // "35歳以上" など下限
        if (preg_match('/(\d+)\s*歳?\s*以上/u', $ageLimit, $m)) {
            $minAge = (int)$m[1];
            if ($engAge >= $minAge) {
                return 15;
            }
            $excluded = true;
            return 0;
        }

        // "35〜45歳" 範囲
        if (preg_match('/(\d+)\s*[〜～]\s*(\d+)\s*歳?/u', $ageLimit, $m)) {
            $minAge = (int)$m[1];
            $maxAge = (int)$m[2];
            if ($engAge >= $minAge && $engAge <= $maxAge) {
                return 15;
            }
            $excluded = true;
            return 0;
        }

        return 8; // パースできない場合は中間点
    }

    private function scoreWorkStyleFit(ProjectMailSource $mail, Engineer $engineer, array &$reasons): int
    {
        $remoteOk  = $mail->remote_ok;
        $engStyle  = $engineer->profile?->work_style; // remote / office / hybrid / null

        if ($remoteOk === null) {
            return 5; // 情報不足
        }

        if ($remoteOk === true) {
            if ($engStyle === 'remote') {
                $reasons[] = 'リモートOK（完全リモート希望）';
                return 10;
            }
            if ($engStyle === 'hybrid') {
                return 8;
            }
            return 6; // 出社希望でもリモートOKなら問題なし
        }

        // remote_ok = false → 出社必須
        if ($engStyle === 'remote') {
            // 出社不可のエンジニアには0点（ただし足切りにはしない）
            return 0;
        }
        if ($engStyle === 'office') {
            $reasons[] = '勤務形態一致（出社）';
            return 10;
        }
        if ($engStyle === 'hybrid') {
            return 7;
        }
        return 5; // 不明
    }

    // ─────────────────────────────────────────────────────────
    // ② スキル適合度（25点）
    // ─────────────────────────────────────────────────────────

    private function scoreSkills(ProjectMailSource $mail, Engineer $engineer): array
    {
        $score   = 0;
        $reasons = [];

        $requiredSkills  = $mail->required_skills  ?? [];
        $preferredSkills = $mail->preferred_skills ?? [];

        $engineerSkillNames = $engineer->engineerSkills
            ->map(fn($es) => mb_strtolower($es->skill?->name ?? ''))
            ->filter()
            ->values()
            ->toArray();

        // 必須スキル（20点）
        if (empty($requiredSkills)) {
            $score += 10; // スキル情報なし: 中間点
        } else {
            $matched = 0;
            $matchedNames = [];
            foreach ($requiredSkills as $reqSkill) {
                if ($this->skillMatches($reqSkill, $engineerSkillNames)) {
                    $matched++;
                    $matchedNames[] = $reqSkill;
                }
            }
            $total   = count($requiredSkills);
            $ratio   = $total > 0 ? $matched / $total : 0;
            $score  += (int)round($ratio * 20);

            if ($matched > 0) {
                $reasons[] = "必須スキル {$matched}/{$total} 適合（" . implode(',', $matchedNames) . '）';
            } else {
                $reasons[] = '必須スキル未適合';
            }
        }

        // 尚可スキル（5点）
        if (!empty($preferredSkills)) {
            $prefMatched = 0;
            foreach ($preferredSkills as $prefSkill) {
                if ($this->skillMatches($prefSkill, $engineerSkillNames)) {
                    $prefMatched++;
                }
            }
            $prefScore = min($prefMatched, 5);
            $score    += $prefScore;
            if ($prefMatched > 0) {
                $reasons[] = "尚可スキル {$prefMatched}件適合";
            }
        }

        return [$score, $reasons];
    }

    /**
     * スキル名の一致判定（部分一致・正規化）
     */
    private function skillMatches(string $targetName, array $engineerSkillNames): bool
    {
        $target = mb_strtolower(trim($targetName));
        if ($target === '') return false;

        foreach ($engineerSkillNames as $name) {
            if ($name === $target) return true;
            if (str_contains($name, $target) || str_contains($target, $name)) return true;
        }

        // アルファベット正規化（Java → java, JavaScript → javascript 等）
        $targetAlpha = preg_replace('/[^a-z0-9]/', '', $target);
        if (strlen($targetAlpha) >= 3) {
            foreach ($engineerSkillNames as $name) {
                $nameAlpha = preg_replace('/[^a-z0-9]/', '', $name);
                if ($nameAlpha === $targetAlpha) return true;
            }
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────
    // ③ 条件適合度（20点）
    // ─────────────────────────────────────────────────────────

    private function scoreConditions(ProjectMailSource $mail, Engineer $engineer): array
    {
        $score   = 0;
        $reasons = [];

        // 単価（10点）
        $score += $this->scorePriceCondition($mail, $engineer, $reasons);

        // 勤務地（5点）
        $score += $this->scoreLocationCondition($mail, $engineer, $reasons);

        // 契約形態（5点）
        $score += $this->scoreContractCondition($mail, $engineer, $reasons);

        return [$score, $reasons];
    }

    private function scorePriceCondition(ProjectMailSource $mail, Engineer $engineer, array &$reasons): int
    {
        $pMin = $mail->unit_price_min ? (float)$mail->unit_price_min : null;
        $pMax = $mail->unit_price_max ? (float)$mail->unit_price_max : $pMin;
        $eMin = $engineer->profile?->desired_unit_price_min ? (float)$engineer->profile->desired_unit_price_min : null;
        $eMax = $engineer->profile?->desired_unit_price_max ? (float)$engineer->profile->desired_unit_price_max : $eMin;

        if ($pMin === null || $eMin === null) {
            return 5; // 情報不足: 中間点
        }

        $pMax = $pMax ?? $pMin;
        $eMax = $eMax ?? $eMin;

        $overlapMin = max($pMin, $eMin);
        $overlapMax = min($pMax, $eMax);

        if ($overlapMin > $overlapMax) {
            // 重複なし
            $gap = $overlapMin - $overlapMax;
            if ($gap <= 10) {
                return 3; // 少し差がある
            }
            $reasons[] = "単価不一致（案件{$pMin}〜{$pMax} / 希望{$eMin}〜{$eMax}万）";
            return 0;
        }

        $overlapRange = $overlapMax - $overlapMin;
        $pRange       = max($pMax - $pMin, 1);
        $eRange       = max($eMax - $eMin, 1);
        $avgRange     = ($pRange + $eRange) / 2;
        $ratio        = min($overlapRange / $avgRange, 1.0);

        $pts = (int)round($ratio * 10);
        if ($pts >= 8) {
            $reasons[] = "単価適合（{$pMin}〜{$pMax}万円）";
        }
        return max($pts, 2);
    }

    private function scoreLocationCondition(ProjectMailSource $mail, Engineer $engineer, array &$reasons): int
    {
        // リモートが既に確認済み（①で加点）なので、勤務地は補助的に確認
        if ($mail->remote_ok === true) {
            return 4; // リモートOKなら場所は問わない
        }

        $mailLoc = mb_strtolower(trim($mail->work_location ?? ''));
        $engLoc  = mb_strtolower(trim($engineer->profile?->preferred_location ?? ''));

        if ($mailLoc === '' || $engLoc === '') {
            return 2; // 情報不足
        }

        // 都道府県レベルの一致確認
        $prefectures = ['東京', '神奈川', '埼玉', '千葉', '大阪', '名古屋', '愛知', '福岡', '北海道'];
        foreach ($prefectures as $pref) {
            $mailHas = str_contains($mailLoc, mb_strtolower($pref));
            $engHas  = str_contains($engLoc, mb_strtolower($pref));
            if ($mailHas && $engHas) {
                $reasons[] = "勤務地一致（{$pref}）";
                return 5;
            }
        }

        if (str_contains($mailLoc, $engLoc) || str_contains($engLoc, $mailLoc)) {
            return 4;
        }

        return 1; // 地域不一致
    }

    private function scoreContractCondition(ProjectMailSource $mail, Engineer $engineer, array &$reasons): int
    {
        $contractType    = $mail->contract_type;        // 準委任/派遣/請負/null
        $affiliationType = $engineer->affiliation_type; // self/bp/null

        if ($contractType === null || $affiliationType === null) {
            return 3; // 情報不足
        }

        // 準委任・請負 → 個人（self）または BP が適合
        if (in_array($contractType, ['準委任', '請負'])) {
            if ($affiliationType === 'self') {
                return 5;
            }
            return 3; // BP でも不適合ではない
        }

        // 派遣 → 制限なし
        if ($contractType === '派遣') {
            return 4;
        }

        return 3;
    }

    // ─────────────────────────────────────────────────────────
    // ④ 稼働・タイミング（10点）
    // ─────────────────────────────────────────────────────────

    private function scoreAvailability(ProjectMailSource $mail, Engineer $engineer): array
    {
        $score   = 0;
        $reasons = [];

        $profile = $engineer->profile;

        // 稼働状況（5点）
        $status = $profile?->availability_status;
        $statusScore = match($status) {
            'available'  => 5,
            'scheduled'  => 3,
            'working'    => 1,
            default      => 3, // 不明: 中間点
        };
        $score += $statusScore;

        if ($status === 'available') {
            $reasons[] = '稼働可能';
        } elseif ($status === 'working') {
            $reasons[] = '現在稼働中';
        } elseif ($status === 'scheduled') {
            $reasons[] = '稼働予定あり';
        }

        // 開始時期（5点）
        $startDate   = $mail->start_date;
        $availableFrom = $profile?->available_from;

        if (!$startDate || !$availableFrom) {
            $score += 3; // 情報不足
            return [$score, $reasons];
        }

        // start_date が "即日" などの文字列の場合
        $projectStart = null;
        try {
            if (preg_match('/^\d{4}-\d{2}/', $startDate)) {
                $projectStart = new \DateTime($startDate);
            } elseif (preg_match('/(\d{4})[年\/\-](\d{1,2})/u', $startDate, $m)) {
                $projectStart = new \DateTime("{$m[1]}-{$m[2]}-01");
            } elseif (str_contains($startDate, '即日') || str_contains($startDate, '即')) {
                $projectStart = new \DateTime();
            }
        } catch (\Throwable) {
            // パース失敗
        }

        if ($projectStart === null) {
            $score += 3;
            return [$score, $reasons];
        }

        try {
            $engAvailable = new \DateTime($availableFrom);
        } catch (\Throwable) {
            $score += 3;
            return [$score, $reasons];
        }

        $diffDays = ($projectStart->getTimestamp() - $engAvailable->getTimestamp()) / 86400;

        if ($diffDays >= 0) {
            // エンジニアの稼働可能日がプロジェクト開始日以前 → 完全OK
            $score += 5;
            $reasons[] = '稼働タイミングOK';
        } elseif ($diffDays >= -30) {
            $score += 4; // 1ヶ月以内のズレ
        } elseif ($diffDays >= -60) {
            $score += 2; // 2ヶ月以内
        } else {
            $score += 0; // 大幅なズレ
        }

        return [$score, $reasons];
    }

    // ─────────────────────────────────────────────────────────
    // ⑤ 商流・実績補正（5点）
    // ─────────────────────────────────────────────────────────

    private function scoreTrackRecord(Engineer $engineer): array
    {
        $score   = 0;
        $reasons = [];

        $pastCount = $engineer->profile?->past_client_count;

        if ($pastCount === null) {
            return [2, $reasons];
        }

        if ($pastCount >= 5) {
            $score = 5;
            $reasons[] = "豊富な実績（{$pastCount}社）";
        } elseif ($pastCount >= 3) {
            $score = 4;
        } elseif ($pastCount >= 1) {
            $score = 3;
        } else {
            $score = 1;
        }

        return [$score, $reasons];
    }
}
