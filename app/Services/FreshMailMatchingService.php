<?php

namespace App\Services;

use App\Models\EngineerMailSource;
use App\Models\ProjectMailSource;
use Illuminate\Support\Collection;

/**
 * 鮮度マッチング機能（過去N日メールから候補抽出）のオーケストレータ。
 *
 * - freshEngineerMails: 案件メール × 過去N日のEMS（/matching/[id]）
 * - freshProjectMails:  技術者メール × 過去N日のPMS（/engineer-mails/[id]）
 *
 * 実体のスコアリングは EngineerMailMatchingService (EMS→仮想Engineer→ProjectMailMatchingService) に委譲。
 *
 * 仕様: docs/470_fresh_mail_matching.md §8
 */
class FreshMailMatchingService
{
    public function __construct(
        private EngineerMailMatchingService $engineerMailMatching,
    ) {}

    /**
     * 案件メール × 過去N日の EMS マッチング。
     *
     * @return Collection<array{ems: EngineerMailSource, score: int, breakdown: array, reasons: string[]}>
     */
    public function freshEngineerMails(ProjectMailSource $projectMail, int $days = 7, int $limit = 50): Collection
    {
        $since = now()->subDays($days);

        $sources = EngineerMailSource::with('email')
            ->where('tenant_id', $projectMail->tenant_id)
            ->where('received_at', '>=', $since)
            ->whereNotIn('status', ['excluded'])
            ->orderByDesc('received_at')
            ->get();

        return $sources
            ->map(function (EngineerMailSource $ems) use ($projectMail) {
                $scored = $this->engineerMailMatching->score($ems, $projectMail);
                return [
                    'ems'       => $ems,
                    'score'     => $scored['score'],
                    'breakdown' => $scored['breakdown'],
                    'reasons'   => $scored['reasons'],
                ];
            })
            ->filter(fn($r) => $r['score'] > 0)
            ->sortByDesc('score')
            ->values()
            ->take($limit);
    }

    /**
     * 技術者メール × 過去N日の PMS マッチング。
     *
     * @return Collection<array{pms: ProjectMailSource, score: int, breakdown: array, reasons: string[]}>
     */
    public function freshProjectMails(EngineerMailSource $engineerMail, int $days = 7, int $limit = 50): Collection
    {
        $since = now()->subDays($days);

        $sources = ProjectMailSource::with('email')
            ->where('tenant_id', $engineerMail->tenant_id)
            ->where('received_at', '>=', $since)
            ->whereNotIn('status', ['excluded'])
            ->orderByDesc('received_at')
            ->get();

        return $sources
            ->map(function (ProjectMailSource $pms) use ($engineerMail) {
                $scored = $this->engineerMailMatching->score($engineerMail, $pms);
                return [
                    'pms'       => $pms,
                    'score'     => $scored['score'],
                    'breakdown' => $scored['breakdown'],
                    'reasons'   => $scored['reasons'],
                ];
            })
            ->filter(fn($r) => $r['score'] > 0)
            ->sortByDesc('score')
            ->values()
            ->take($limit);
    }
}
