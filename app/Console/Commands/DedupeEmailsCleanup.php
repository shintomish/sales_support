<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * rfc_message_id 重複メールの過去分クリーンアップ（一回性・再実行可）。
 *
 * Kagoya 二重配送で同一 Message-ID のメールが複数行でき、それぞれ採点されて
 * 案件/技術者カード(PMS/EMS)が重複していた。dedup コード(storeRawMessage)で今後分は
 * 防止済みだが、過去分はこのコマンドで統合する。
 *
 * keep ポリシー: 各 (tenant_id, rfc_message_id) グループで
 *   (カード有 desc, 添付有 desc, id desc) の1位を残し、他を削除。
 *   → カード/添付を持つ行を必ず残すため無損失（検証済: 失う group 0）。
 * 削除は FK CASCADE で重複 PMS/EMS/添付/match_results も消えるが、
 * 安全ガードで「削除対象 PMS/EMS が送信履歴・キャンペーン・match_results から
 * 参照されていないこと」を確認し、参照があれば中止する。
 *
 * cascade による statement_timeout を避けるため LIMIT バッチで削除する
 * (CLAUDE memory: bounce_cascade_timeout)。
 */
class DedupeEmailsCleanup extends Command
{
    protected $signature = 'emails:dedupe-cleanup
        {--execute : 実削除する（既定は dry-run で対象集計のみ）}
        {--batch=200 : 1バッチあたりの削除件数}
        {--tenant= : 特定 tenant_id のみ対象}';

    protected $description = 'rfc_message_id が重複するメールを keep ポリシーで統合削除する（過去分掃除）';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $batch   = max(1, (int) $this->option('batch'));
        $tenant  = $this->option('tenant');
        $label   = $execute ? '' : '[DRY-RUN] ';

        $tenantFilter = $tenant !== null ? 'and e.tenant_id = ' . (int) $tenant : '';
        $tenantFilter2 = $tenant !== null ? 'and tenant_id = ' . (int) $tenant : '';

        // keep=各グループ (has_card desc, has_att desc, id desc) 1位、他を削除
        $sql = "
            with d as (
                select e.id, e.tenant_id, e.rfc_message_id,
                    (exists(select 1 from project_mail_sources p where p.email_id=e.id)
                     or exists(select 1 from engineer_mail_sources x where x.email_id=e.id))::int has_card,
                    (exists(select 1 from email_attachments a where a.email_id=e.id))::int has_att
                from emails e
                where e.rfc_message_id is not null {$tenantFilter}
                  and (e.tenant_id, e.rfc_message_id) in (
                      select tenant_id, rfc_message_id from emails
                      where rfc_message_id is not null {$tenantFilter2}
                      group by tenant_id, rfc_message_id having count(*) > 1)
            ),
            ranked as (
                select *, row_number() over (
                    partition by tenant_id, rfc_message_id
                    order by has_card desc, has_att desc, id desc) rn
                from d
            )
            select id from ranked where rn > 1
        ";
        $deleteIds = array_map(fn($r) => $r->id, DB::select($sql));

        if (empty($deleteIds)) {
            $this->info("{$label}重複なし。削除対象 0 件。");
            return self::SUCCESS;
        }

        // 削除対象が持つ PMS / EMS
        $pmsIds = DB::table('project_mail_sources')->whereIn('email_id', $deleteIds)->pluck('id')->all();
        $emsIds = DB::table('engineer_mail_sources')->whereIn('email_id', $deleteIds)->pluck('id')->all();
        $attCnt = DB::table('email_attachments')->whereIn('email_id', $deleteIds)->count();

        // ── 安全ガード: 削除対象の PMS/EMS に下流参照があれば中止 ──
        // 空配列のとき orWhereIn 単独で全件カウントにならないよう closure で括る。
        $matchResults = 0;
        if ($pmsIds || $emsIds) {
            $matchResults = DB::table('requirement_match_results')
                ->where(function ($q) use ($pmsIds, $emsIds) {
                    if ($pmsIds) $q->whereIn('project_mail_source_id', $pmsIds);
                    if ($emsIds) $q->orWhereIn('engineer_mail_source_id', $emsIds);
                })->count();
        }
        $msh = $pmsIds ? DB::table('mail_send_histories')->whereIn('project_mail_id', $pmsIds)->count() : 0;
        $camp = 0;
        if ($pmsIds || $emsIds) {
            $camp = DB::table('delivery_campaigns')
                ->where(function ($q) use ($pmsIds, $emsIds) {
                    if ($pmsIds) $q->whereIn('project_mail_id', $pmsIds);
                    if ($emsIds) $q->orWhereIn('engineer_mail_source_id', $emsIds);
                })->count();
        }

        $this->line("{$label}削除対象メール: " . count($deleteIds) . " 件");
        $this->line("{$label}  cascade 削除される PMS=" . count($pmsIds) . " / EMS=" . count($emsIds) . " / 添付=" . $attCnt);
        $this->line("{$label}  下流参照 — match_results={$matchResults} / mail_send_histories={$msh} / delivery_campaigns={$camp}");

        if ($matchResults > 0 || $msh > 0 || $camp > 0) {
            $this->error('安全ガード: 削除対象の PMS/EMS が下流(match_results/送信履歴/キャンペーン)から参照されています。'
                . '無損失でないため中止します。個別調査が必要です。');
            return self::FAILURE;
        }

        if (!$execute) {
            $this->info("{$label}--execute 指定で実削除します（既定は dry-run）。");
            return self::SUCCESS;
        }

        // ── 実削除: LIMIT バッチで cascade timeout を回避 ──
        $total = 0;
        foreach (array_chunk($deleteIds, $batch) as $chunk) {
            $deleted = DB::table('emails')->whereIn('id', $chunk)->delete();
            $total += $deleted;
            $this->line("削除 {$total}/" . count($deleteIds));
        }

        Log::info('[DedupeEmailsCleanup] 重複メール統合削除 完了', [
            'deleted_emails' => $total,
            'cascaded_pms'   => count($pmsIds),
            'cascaded_ems'   => count($emsIds),
            'tenant'         => $tenant,
        ]);
        $this->info("実削除完了: {$total} 件");

        return self::SUCCESS;
    }
}
