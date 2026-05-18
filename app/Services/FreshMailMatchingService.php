<?php

namespace App\Services;

use App\Models\EngineerMailSource;
use App\Models\ProjectMailSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 鮮度マッチング機能（過去N日メールから候補抽出）のオーケストレータ。
 *
 * - freshEngineerMails: 案件メール × 過去N日のEMS（/matching/[id]）
 * - freshProjectMails:  技術者メール × 過去N日のPMS（/engineer-mails/[id]）
 *
 * 実体のスコアリングは EngineerMailMatchingService (EMS→仮想Engineer→ProjectMailMatchingService) に委譲。
 *
 * 仕様: docs/470_fresh_mail_matching.md §8
 *
 * パフォーマンス: 本番では過去7日でEMSが2万件超になることがあるため、
 * SQL レベルで (a) スキル jsonb 重複, (b) EMS.score 下限, (c) 件数上限 で絞ってから
 * PHP スコアリングに渡す。
 */
class FreshMailMatchingService
{
    /** 1 リクエストで PHP スコアリングする上限件数 */
    private const HARD_LIMIT = 300;
    /** EMS/PMS.score (抽出品質スコア) の下限 (情報量が少ないものを除外) */
    private const QUALITY_FLOOR = 30;
    /** マッチ結果スコアの表示下限 (◎ 80+ / ○ 60-79 のうち ○ 上位以上を表示) */
    private const RESULT_SCORE_FLOOR = 70;

    public function __construct(
        private EngineerMailMatchingService $engineerMailMatching,
    ) {}

    /**
     * 案件メール × 過去N日の EMS マッチング。
     */
    public function freshEngineerMails(ProjectMailSource $projectMail, int $days = 7, int $limit = 50): Collection
    {
        $since = now()->subDays($days);

        $query = EngineerMailSource::with('email')
            ->where('tenant_id', $projectMail->tenant_id)
            ->where('received_at', '>=', $since)
            ->whereNotIn('status', ['excluded'])
            ->where('score', '>=', self::QUALITY_FLOOR);

        // スキル jsonb 重複で事前フィルタ（案件側に required_skills がある場合のみ）
        $this->applySkillOverlap($query, 'engineer_mail_sources.skills', $projectMail->required_skills ?? []);

        // 単価フィルタ: 案件 unit_price_max >= 技術者 unit_price_max (確定済設計判断)
        // PMS.unit_price_max は decimal、EMS.unit_price_max は smallint なので int 化必須
        if ($projectMail->unit_price_max) {
            $pmsMax = (int) $projectMail->unit_price_max;
            $query->where(function ($q) use ($pmsMax) {
                $q->whereNull('unit_price_max')
                  ->orWhere('unit_price_max', '<=', $pmsMax);
            });
        }

        $sources = $query
            ->orderByDesc('received_at')
            ->limit(self::HARD_LIMIT)
            ->get();

        // 同一人物が短期間に複数回送ってくるケースを dedup (email+name+affiliation 3キー)
        // received_at DESC で取得済みなので、最初に現れたもの = 最新 を残す
        $sources = $this->dedupEmsBy3Key($sources);

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
            ->filter(fn($r) => $r['score'] >= self::RESULT_SCORE_FLOOR)
            ->sortByDesc('score')
            ->values()
            ->take($limit);
    }

    /**
     * 技術者メール × 過去N日の PMS マッチング。
     */
    public function freshProjectMails(EngineerMailSource $engineerMail, int $days = 7, int $limit = 50): Collection
    {
        $since = now()->subDays($days);

        $query = ProjectMailSource::with('email')
            ->where('tenant_id', $engineerMail->tenant_id)
            ->where('received_at', '>=', $since)
            ->whereNotIn('status', ['excluded'])
            ->where('score', '>=', self::QUALITY_FLOOR);

        $this->applySkillOverlap($query, 'project_mail_sources.required_skills', $engineerMail->skills ?? []);

        // 単価フィルタ: 技術者 unit_price_max <= 案件 unit_price_max
        // EMS.unit_price_max は smallint、PMS.unit_price_max は decimal なので int 化必須
        if ($engineerMail->unit_price_max) {
            $emsMax = (int) $engineerMail->unit_price_max;
            $query->where(function ($q) use ($emsMax) {
                $q->whereNull('unit_price_max')
                  ->orWhere('unit_price_max', '>=', $emsMax);
            });
        }

        $sources = $query
            ->orderByDesc('received_at')
            ->limit(self::HARD_LIMIT)
            ->get();

        // 同一案件が複数 BP から flood で来るケースを dedup
        // (送信元アドレス + 件名 + 顧客名 が一致 = 同じ案件の二次/三次配信とみなす)
        $sources = $this->dedupPmsBy3Key($sources);

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
            ->filter(fn($r) => $r['score'] >= self::RESULT_SCORE_FLOOR)
            ->sortByDesc('score')
            ->values()
            ->take($limit);
    }

    /**
     * EMS を email + name + affiliation の 3 キーで dedup (大文字小文字無視・null/空欄も比較対象)。
     * Collection は received_at DESC で入ってくる前提なので、最初に現れたもの = 最新を残す。
     *
     * @param  Collection<int, EngineerMailSource>  $sources
     * @return Collection<int, EngineerMailSource>
     */
    private function dedupEmsBy3Key(Collection $sources): Collection
    {
        return $sources->unique(function (EngineerMailSource $ems) {
            $email       = mb_strtolower(trim((string) ($ems->email?->from_address ?? '')));
            $name        = mb_strtolower(trim((string) ($ems->name ?? '')));
            $affiliation = mb_strtolower(trim((string) ($ems->affiliation ?? '')));
            return "{$email}|{$name}|{$affiliation}";
        })->values();
    }

    /**
     * PMS を from_address + 件名 + 顧客名 の 3 キーで dedup。
     *
     * @param  Collection<int, ProjectMailSource>  $sources
     * @return Collection<int, ProjectMailSource>
     */
    private function dedupPmsBy3Key(Collection $sources): Collection
    {
        return $sources->unique(function (ProjectMailSource $pms) {
            $email    = mb_strtolower(trim((string) ($pms->email?->from_address ?? '')));
            $subject  = mb_strtolower(trim((string) ($pms->email?->subject ?? '')));
            $customer = mb_strtolower(trim((string) ($pms->customer_name ?? '')));
            return "{$email}|{$subject}|{$customer}";
        })->values();
    }

    /**
     * PostgreSQL の jsonb_array_elements_text + EXISTS で skill 重複を SQL レベルでフィルタ。
     * 関連 ?| 演算子は Laravel/PDO のバインディング解釈と衝突するため使わない。
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $jsonColumn  fully-qualified カラム名 (例: 'engineer_mail_sources.skills')
     * @param  array<string>  $skills  比較対象スキル名リスト
     */
    private function applySkillOverlap(Builder $query, string $jsonColumn, array $skills): void
    {
        $skills = array_values(array_filter(array_map('trim', $skills)));
        if (empty($skills)) {
            return; // 比較対象なし: フィルタしない (全件スコアリング)
        }
        $placeholders = implode(',', array_fill(0, count($skills), '?'));
        $query->whereNotNull($jsonColumn)
              ->whereRaw(
                  "EXISTS (SELECT 1 FROM jsonb_array_elements_text({$jsonColumn}::jsonb) AS e WHERE e IN ({$placeholders}))",
                  $skills,
              );
    }
}
