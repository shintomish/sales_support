<?php

namespace App\Services;

use App\Models\Engineer;
use App\Models\EngineerMailSource;
use App\Models\EngineerProfile;
use App\Models\EngineerSkill;
use App\Models\ProjectMailSource;
use App\Models\Skill;

/**
 * 技術者メール (EngineerMailSource) × 案件メール (ProjectMailSource) スコアリング。
 *
 * 実体は EMS を DB 未保存の仮想 Engineer に変換し、
 * 既存 ProjectMailMatchingService::score(PMS, Engineer) を流用する。
 *
 * 仕様: docs/470_fresh_mail_matching.md §8.3
 */
class EngineerMailMatchingService
{
    public function __construct(
        private ProjectMailMatchingService $projectMailMatching,
    ) {}

    /**
     * EMS × PMS のスコアを計算。
     *
     * @return array{score:int, breakdown:array, reasons:string[]}
     */
    public function score(EngineerMailSource $ems, ProjectMailSource $pms): array
    {
        $virtualEngineer = $this->toVirtualEngineer($ems);
        $scored = $this->projectMailMatching->score($pms, $virtualEngineer);
        return [
            'score'     => $scored['score'],
            'breakdown' => $scored['breakdown'],
            'reasons'   => $scored['reasons'],
        ];
    }

    /**
     * EMS を DB 未保存の仮想 Engineer に変換する。
     * profile / engineerSkills を setRelation で埋め込み、score() が in-memory で動作するようにする。
     *
     * 注意:
     *  - スキルマスタ (Skill) は副作用で firstOrCreate される（既存 registerEngineer と同じ挙動）
     *  - EMS に無いフィールド（preferred_location, availability_status, past_client_count 等）は
     *    null のままで OK（scoring 側で「情報不足 = 中間点」として扱われる）
     */
    public function toVirtualEngineer(EngineerMailSource $ems): Engineer
    {
        $virtualEngineer = new Engineer([
            'tenant_id'        => $ems->tenant_id,
            'name'             => $ems->name,
            'email'            => $ems->email?->from_address,
            'affiliation'      => $ems->affiliation,
            'affiliation_type' => $this->normalizeAffiliationType($ems->affiliation_type),
            'nearest_station'  => $ems->nearest_station,
            'age'              => $ems->age,
        ]);

        $profile = new EngineerProfile([
            'tenant_id'              => $ems->tenant_id,
            'desired_unit_price_min' => $ems->unit_price_min,
            'desired_unit_price_max' => $ems->unit_price_max,
            'available_from'         => $this->normalizeAvailableFrom($ems->available_from),
        ]);
        $virtualEngineer->setRelation('profile', $profile);

        $engineerSkills = collect((array) ($ems->skills ?? []))
            ->map(fn($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->map(function (string $name) use ($ems) {
                $skill = Skill::firstOrCreate(['name' => $name], ['category' => 'other']);
                $es = new EngineerSkill([
                    'tenant_id' => $ems->tenant_id,
                    'skill_id'  => $skill->id,
                ]);
                $es->setRelation('skill', $skill);
                return $es;
            })
            ->values();
        $virtualEngineer->setRelation('engineerSkills', $engineerSkills);

        return $virtualEngineer;
    }

    /**
     * EMS.affiliation_type は経路により英語enum or 日本語表示値の両方があり得る。
     * スコアリング側 ('self'/'bp') と整合させるため英語enumに正規化する。
     */
    private function normalizeAffiliationType(?string $type): ?string
    {
        if ($type === null || $type === '') return null;
        $reverseMap = [
            '自社正社員'   => 'self',
            '一社先正社員' => 'first_sub',
            'BP'           => 'bp',
            'BP要員'       => 'bp_member',
            '契約社員'     => 'contract',
            '個人事業主'   => 'freelance',
            '入社予定'     => 'joining',
            '採用予定'     => 'hiring',
        ];
        return $reverseMap[$type] ?? $type;
    }

    /**
     * EMS.available_from は「即日」「2026.4」等の自由文字列。
     * EngineerProfile.available_from は date:Y-m-d cast。
     * パース不能なら null 返す（scoring 側で「情報不足」として処理される）。
     */
    private function normalizeAvailableFrom(?string $raw): ?string
    {
        if ($raw === null || $raw === '') return null;
        $raw = trim($raw);

        if (str_contains($raw, '即日') || str_contains($raw, '即')) {
            return now()->format('Y-m-d');
        }
        if (preg_match('/^(\d{4})[-\/\.](\d{1,2})(?:[-\/\.](\d{1,2}))?/u', $raw, $m)) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            $d = isset($m[3]) ? (int) $m[3] : 1;
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        return null;
    }
}
