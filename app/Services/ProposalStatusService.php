<?php

namespace App\Services;

use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use App\Models\Engineer;
use App\Models\EngineerMailSource;
use App\Models\ProjectMailSource;
use Illuminate\Support\Collection;

/**
 * 鮮度マッチング画面のバッジ判定ヘルパー。
 *
 * バッジ 3 値:
 *   - new       : Engineer マスタに未登録
 *   - registered: 登録済だが当該 project_mail への提案未送
 *   - proposed  : 当該 project_mail への提案送信済
 *
 * 仕様: docs/470_fresh_mail_matching.md §8.4
 */
class ProposalStatusService
{
    /**
     * EMS 群について、対象 project_mail への提案ステータスを一括判定。
     * 戻り値: ems_id => ['badge' => 'new'|'registered'|'proposed', 'engineer_id' => ?int]
     *
     * @param Collection<EngineerMailSource> $emsList
     */
    public function buildEmsStatusMap(Collection $emsList, ProjectMailSource $projectMail): array
    {
        if ($emsList->isEmpty()) return [];

        $tenantId = $projectMail->tenant_id;

        // 1. 登録済 Engineer の解決
        $resolved = $this->resolveEngineers($emsList, $tenantId);
        $engineerIds = collect($resolved)->filter()->values()->all();

        // 2. 当該 project_mail への提案送信履歴を一括取得
        $proposedEngineerIds = [];
        if (!empty($engineerIds)) {
            $proposedEngineerIds = DeliverySendHistory::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('engineer_id', $engineerIds)
                ->where('status', 'sent')
                ->whereHas('campaign', function ($q) use ($projectMail) {
                    $q->where('project_mail_id', $projectMail->id);
                })
                ->pluck('engineer_id')
                ->unique()
                ->all();
            $proposedEngineerIds = array_flip($proposedEngineerIds);
        }

        // 3. ステータス組み立て
        $result = [];
        foreach ($emsList as $ems) {
            $engineerId = $resolved[$ems->id] ?? null;
            if ($engineerId === null) {
                $result[$ems->id] = ['badge' => 'new', 'engineer_id' => null];
            } elseif (isset($proposedEngineerIds[$engineerId])) {
                $result[$ems->id] = ['badge' => 'proposed', 'engineer_id' => $engineerId];
            } else {
                $result[$ems->id] = ['badge' => 'registered', 'engineer_id' => $engineerId];
            }
        }
        return $result;
    }

    /**
     * PMS 群について、対象 engineer_mail への提案ステータスを一括判定。
     *
     * @param Collection<ProjectMailSource> $pmsList
     */
    public function buildPmsStatusMap(Collection $pmsList, EngineerMailSource $engineerMail): array
    {
        if ($pmsList->isEmpty()) return [];

        $tenantId = $engineerMail->tenant_id;

        // 案件側は customer_name+title で別案件を統合できないため、
        // 「当該 EMS×PMS の組み合わせで campaign が既に存在するか」で判定する。
        $sentPmsIds = [];
        $campaignIds = DeliveryCampaign::query()
            ->where('tenant_id', $tenantId)
            ->where('engineer_mail_source_id', $engineerMail->id)
            ->whereIn('project_mail_id', $pmsList->pluck('id'))
            ->pluck('project_mail_id')
            ->unique()
            ->all();
        $sentPmsIds = array_flip($campaignIds);

        $result = [];
        foreach ($pmsList as $pms) {
            $result[$pms->id] = [
                'badge' => isset($sentPmsIds[$pms->id]) ? 'proposed' : 'new',
            ];
        }
        return $result;
    }

    /**
     * EMS リストに対し既存 Engineer を解決する。
     * 解決順:
     *   (1) engineer.engineer_mail_source_id 一致
     *   (2) email + name + affiliation の3項目一致
     *
     * @return array<int, int|null> ems_id => engineer_id (or null)
     */
    private function resolveEngineers(Collection $emsList, int $tenantId): array
    {
        $emsIds = $emsList->pluck('id')->all();

        // (1) engineer_mail_source_id でリンク済の Engineer 一括取得
        $byEmsId = Engineer::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('engineer_mail_source_id', $emsIds)
            ->pluck('id', 'engineer_mail_source_id')
            ->all();

        // (2) email + name + affiliation でマッチング
        // 比較用に EMS ごとの正規化キーを作る
        $keys = [];
        foreach ($emsList as $ems) {
            if (isset($byEmsId[$ems->id])) continue;
            $email = $ems->email?->from_address;
            if (!$email || !$ems->name) continue;
            $keys[$ems->id] = $this->dedupKey($email, $ems->name, $ems->affiliation);
        }

        $byTriple = [];
        if (!empty($keys)) {
            $emails = collect($emsList)
                ->filter(fn($e) => isset($keys[$e->id]))
                ->map(fn($e) => $e->email?->from_address)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $candidates = Engineer::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('email', $emails)
                ->get(['id', 'email', 'name', 'affiliation']);

            $indexed = [];
            foreach ($candidates as $eng) {
                $k = $this->dedupKey($eng->email, $eng->name, $eng->affiliation);
                $indexed[$k] = $eng->id;
            }
            foreach ($keys as $emsId => $key) {
                if (isset($indexed[$key])) {
                    $byTriple[$emsId] = $indexed[$key];
                }
            }
        }

        $result = [];
        foreach ($emsList as $ems) {
            $result[$ems->id] = $byEmsId[$ems->id] ?? $byTriple[$ems->id] ?? null;
        }
        return $result;
    }

    /**
     * 重複検出キー（email + name + affiliation の3項目）の正規化文字列。
     * 大小無視・前後空白除去・affiliation null は空文字扱い。
     */
    public function dedupKey(?string $email, ?string $name, ?string $affiliation): string
    {
        return implode('|', [
            mb_strtolower(trim((string) $email)),
            trim((string) $name),
            trim((string) ($affiliation ?? '')),
        ]);
    }
}
