<?php

namespace App\Services;

use App\Models\DeliverySendHistory;
use App\Models\Email;
use App\Models\EngineerMailSource;
use App\Models\ProjectMailSource;
use App\Models\SesContract;
use App\Scopes\TenantScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 朝の日次レポート 組立サービス
 *
 * パイプライン:
 *   [1] データ収集（受信件数 / 有効と思われる案件・技術者メール / delivery / SES契約）
 *   [2] 品質ゲート: 0件セクションは除外
 *   [3] Haiku で「優先順位付き今日のアクション」サマリー（要対応 >= 1 件のとき）
 *
 * 「有効と思われるメールリスト」:
 *   - 前日受信した PMS / EMS のうち quality score >= 70 を親として上位5件抽出
 *   - 各親に対し過去3日の鮮度マッチング (FreshMailMatchingService) で上位3件のマッチを付ける
 *   - マッチ0件の親はスキップして次候補に繰上げ
 *
 * テナントスコープ: tenant_id を指定して呼ぶ（Auth context が無いコマンド実行で動かすため）。
 */
class DailyReportBuilder
{
    private const SCORE_THRESHOLD = 70;
    /** 「有効と思われるメールリスト」親メール件数 */
    private const EFFECTIVE_PARENTS = 5;
    /** 「有効と思われるメールリスト」親メール 1 件あたりのマッチ表示件数 */
    private const EFFECTIVE_MATCHES = 3;
    /** マッチ検索の探索期間 (日) */
    private const EFFECTIVE_FRESH_DAYS = 3;

    public function __construct(
        private readonly ClaudeService $claude,
        private readonly FreshMailMatchingService $freshMailMatching,
        private readonly ContactFormSubmissionParser $contactFormParser,
    ) {}

    /**
     * @return array{
     *   tenant_id: int,
     *   target_date: string,
     *   sections: array<string, array<string,mixed>>,
     *   action_total: int,
     *   ai_summary: ?string
     * }
     */
    public function build(int $tenantId): array
    {
        $today     = Carbon::today('Asia/Tokyo');
        $yesterday = $today->copy()->subDay();

        $sections = [];

        $sections['inbox']                    = $this->collectInbox($tenantId, $yesterday, $today);
        $sections['contact_forms']            = $this->collectContactForms($tenantId, $yesterday, $today);
        $sections['effective_project_mails']  = $this->collectEffectiveProjectMails($tenantId, $yesterday, $today);
        $sections['effective_engineer_mails'] = $this->collectEffectiveEngineerMails($tenantId, $yesterday, $today);
        $sections['delivery']                 = $this->collectDeliveryStats($tenantId, $yesterday, $today);
        $sections['expiring']                 = $this->collectExpiringContracts($tenantId, 30);

        // 品質ゲート: 0 件セクションは除外（ただし「有効と思われるメールリスト」は0件でも常に表示）
        $alwaysShow = ['effective_project_mails', 'effective_engineer_mails'];
        $sections = array_filter(
            $sections,
            fn ($s, $k) => in_array($k, $alwaysShow, true) || ($s['count'] ?? 0) > 0,
            ARRAY_FILTER_USE_BOTH,
        );

        $actionTotal = ($sections['effective_project_mails']['count']  ?? 0)
                     + ($sections['effective_engineer_mails']['count'] ?? 0)
                     + ($sections['expiring']['count']                 ?? 0);

        $aiSummary = null;
        if ($actionTotal >= 1) {
            $aiSummary = $this->summarizeWithHaiku($sections);
        }

        return [
            'tenant_id'    => $tenantId,
            'target_date'  => $yesterday->toDateString(),
            'sections'     => $sections,
            'action_total' => $actionTotal,
            'ai_summary'   => $aiSummary,
        ];
    }

    /** [1] 受信メール件数（対象日24h、分類別） */
    private function collectInbox(int $tenantId, Carbon $from, Carbon $to): array
    {
        $rows = Email::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereBetween('received_at', [$from, $to])
            ->select('category', DB::raw('count(*) as cnt'))
            ->groupBy('category')
            ->pluck('cnt', 'category')
            ->toArray();

        $total = array_sum($rows);
        return [
            'count'    => $total,
            'engineer' => (int) ($rows['engineer'] ?? 0),
            'project'  => (int) ($rows['project']  ?? 0),
            'other'    => (int) ($rows['other']    ?? 0),
        ];
    }

    /**
     * [1-2] お問い合わせフォーム投稿（SmoothContact 経由・category='other'）
     *  - 対象日に受信した other メールのうち本文に「[ 御社名 ]」を持つフォーム投稿のみ
     *    （プレーンな営業メールはラベルが無いので自然に除外される）
     *  - ヘッダ項目を ContactFormSubmissionParser で構造化（お問い合わせ内容・URL は載せない）
     *  - 受信情報＝FYI のため action_total / AI サマリには加算しない
     */
    private function collectContactForms(int $tenantId, Carbon $from, Carbon $to): array
    {
        $emails = Email::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereBetween('received_at', [$from, $to])
            ->where('category', 'other')
            ->where('body_text', 'like', '%[ 御社名 ]%')
            ->orderByDesc('received_at')
            ->take(50)
            ->get(['id', 'body_text', 'received_at']);

        $items = $emails->map(function (Email $e) {
            $parsed = $this->contactFormParser->parse($e->body_text ?? '');
            return array_merge($parsed, [
                'email_id'    => $e->id,
                'received_at' => optional($e->received_at)->format('n/j H:i'),
            ]);
        })->values()->toArray();

        return [
            'count' => count($items),
            'list'  => $items,
        ];
    }

    /**
     * [2] 有効と思われるメールリスト（案件側）
     *  - 親: 前日受信した PMS で score >= 70（重複排除後 上位 EFFECTIVE_PARENTS 件）
     *  - 各親に対し過去 EFFECTIVE_FRESH_DAYS 日の EMS から上位 EFFECTIVE_MATCHES 件マッチを付ける
     *  - マッチ 0 件の親はスキップして次候補に繰上げ
     */
    private function collectEffectiveProjectMails(int $tenantId, Carbon $from, Carbon $to): array
    {
        $candidates = ProjectMailSource::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->where('score', '>=', self::SCORE_THRESHOLD)
            ->whereNotIn('status', ['excluded'])
            ->orderByDesc('score')
            ->orderByDesc('created_at')
            ->take(40)
            ->get();

        // 同一案件の重複排除
        $candidates = $candidates->unique(function (ProjectMailSource $m) {
            return ($m->title ?: '?') . '|' . ($m->customer_name ?: '?');
        })->values();

        $items = [];
        foreach ($candidates as $pms) {
            if (count($items) >= self::EFFECTIVE_PARENTS) break;
            $matches = $this->freshMailMatching->freshEngineerMails(
                $pms,
                self::EFFECTIVE_FRESH_DAYS,
                self::EFFECTIVE_MATCHES,
                self::SCORE_THRESHOLD,
            );
            if ($matches->isEmpty()) continue;

            $items[] = [
                'id'             => $pms->id,
                'title'          => $pms->title ?: '（件名未取得）',
                'customer_name'  => $pms->customer_name,
                'unit_price_max' => $pms->unit_price_max,
                'skills_summary' => $this->summarizeSkills($pms->required_skills ?? []),
                'score'          => (int) $pms->score,
                'received_at'    => optional($pms->created_at)->format('n/j H:i'),
                'matches'        => $matches->map(function (array $r) {
                    /** @var EngineerMailSource $ems */
                    $ems = $r['ems'];
                    return [
                        'id'             => $ems->id,
                        'name'           => $ems->name ?: '（名前未取得）',
                        'affiliation'    => $ems->affiliation,
                        'unit_price_max' => $ems->unit_price_max,
                        'skills_summary' => $this->summarizeSkills($ems->skills ?? []),
                        'score'          => (int) $r['score'],
                    ];
                })->values()->toArray(),
            ];
        }

        return [
            'count' => count($items),
            'list'  => $items,
        ];
    }

    /**
     * [3] 有効と思われるメールリスト（技術者側）
     *  - 親: 前日受信した EMS で score >= 70（重複排除後 上位 EFFECTIVE_PARENTS 件）
     *  - 各親に対し過去 EFFECTIVE_FRESH_DAYS 日の PMS から上位 EFFECTIVE_MATCHES 件マッチを付ける
     */
    private function collectEffectiveEngineerMails(int $tenantId, Carbon $from, Carbon $to): array
    {
        $candidates = EngineerMailSource::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->where('score', '>=', self::SCORE_THRESHOLD)
            ->whereNotIn('status', ['excluded'])
            ->orderByDesc('score')
            ->orderByDesc('created_at')
            ->take(40)
            ->get();

        $candidates = $candidates->unique(function (EngineerMailSource $m) {
            $skillsKey = is_array($m->skills) ? implode(',', $m->skills) : '';
            return ($m->name ?: '?') . '|' . $skillsKey;
        })->values();

        $items = [];
        foreach ($candidates as $ems) {
            if (count($items) >= self::EFFECTIVE_PARENTS) break;
            $matches = $this->freshMailMatching->freshProjectMails(
                $ems,
                self::EFFECTIVE_FRESH_DAYS,
                self::EFFECTIVE_MATCHES,
                self::SCORE_THRESHOLD,
            );
            if ($matches->isEmpty()) continue;

            $items[] = [
                'id'             => $ems->id,
                'name'           => $ems->name ?: '（名前未取得）',
                'affiliation'    => $ems->affiliation,
                'unit_price_max' => $ems->unit_price_max,
                'skills_summary' => $this->summarizeSkills($ems->skills ?? []),
                'score'          => (int) $ems->score,
                'received_at'    => optional($ems->created_at)->format('n/j H:i'),
                'matches'        => $matches->map(function (array $r) {
                    /** @var ProjectMailSource $pms */
                    $pms = $r['pms'];
                    return [
                        'id'             => $pms->id,
                        'title'          => $pms->title ?: '（件名未取得）',
                        'customer_name'  => $pms->customer_name,
                        'unit_price_max' => $pms->unit_price_max,
                        'skills_summary' => $this->summarizeSkills($pms->required_skills ?? []),
                        'score'          => (int) $r['score'],
                    ];
                })->values()->toArray(),
            ];
        }

        return [
            'count' => count($items),
            'list'  => $items,
        ];
    }

    /** [4] 提案メール送信実績（対象日24h、status別） */
    private function collectDeliveryStats(int $tenantId, Carbon $from, Carbon $to): array
    {
        $rows = DeliverySendHistory::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->select('status', DB::raw('count(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $total = array_sum($rows);
        return [
            'count'   => $total,
            'sent'    => (int) ($rows['sent']    ?? 0),
            'failed'  => (int) ($rows['failed']  ?? 0),
            'replied' => (int) ($rows['replied'] ?? 0),
        ];
    }

    /** [5] 期限切れ間近のSES契約（今後 N 日以内、最大20件） */
    private function collectExpiringContracts(int $tenantId, int $days): array
    {
        $today = Carbon::today('Asia/Tokyo');
        $limit = $today->copy()->addDays($days);

        $contracts = SesContract::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('contract_period_end')
            ->whereBetween('contract_period_end', [$today->toDateString(), $limit->toDateString()])
            ->orderBy('contract_period_end')
            ->take(20)
            ->get(['id', 'deal_id', 'engineer_name', 'contract_period_end']);

        $dealIds = $contracts->pluck('deal_id')->filter()->unique()->values();
        $customers = collect();
        if ($dealIds->isNotEmpty()) {
            $customers = DB::table('deals')
                ->leftJoin('customers', 'customers.id', '=', 'deals.customer_id')
                ->whereIn('deals.id', $dealIds)
                ->select('deals.id as deal_id', 'deals.title as deal_title', 'customers.company_name as customer_name')
                ->get()
                ->keyBy('deal_id');
        }

        return [
            'count' => $contracts->count(),
            'list'  => $contracts->map(function (SesContract $c) use ($today, $customers) {
                $end = Carbon::parse($c->contract_period_end);
                $row = $customers->get($c->deal_id);
                return [
                    'id'             => $c->id,
                    'deal_id'        => $c->deal_id,
                    'engineer_name'  => $c->engineer_name ?: '（未設定）',
                    'customer_name'  => $row->customer_name ?? null,
                    'deal_title'     => $row->deal_title ?? null,
                    'end_date'       => $end->toDateString(),
                    'days_left'      => (int) $today->diffInDays($end, false),
                ];
            })->toArray(),
        ];
    }

    /**
     * Claude Haiku で「今日のアクション」を3つ生成。連続行（空行なし）。
     */
    private function summarizeWithHaiku(array $sections): ?string
    {
        $prompt = $this->buildSummaryPrompt($sections);
        try {
            $text = $this->claude->ask($prompt);
            // Haiku が番号間に空行を入れがちなので、連続行（空行なし）に正規化
            $text = preg_replace("/\n\s*\n/u", "\n", trim($text)) ?? trim($text);
            return $text;
        } catch (Throwable $e) {
            Log::warning('DailyReport Haiku summary failed', ['err' => $e->getMessage()]);
            return null;
        }
    }

    private function buildSummaryPrompt(array $sections): string
    {
        $prj = $sections['effective_project_mails']  ?? null;
        $eng = $sections['effective_engineer_mails'] ?? null;
        $exp = $sections['expiring']                 ?? null;

        $lines = [];
        $lines[] = "あなたはSES企業の営業マネージャー向けのレポーターです。";
        $lines[] = "以下の【状況サマリ】に書かれた事実だけを根拠に、今日確認すべきトピックを最大3つ、優先順位順に1〜2行で箇条書きしてください。";
        $lines[] = "";
        $lines[] = "厳守ルール:";
        $lines[] = "- 状況サマリに登場する案件名・技術者名・契約名のみを引用してよい。";
        $lines[] = "- システム内に存在を確認できないリソース（例: 「PL/SQL人材を優先的にマッチング」等）の推奨は禁止。";
        $lines[] = "- 「マッチングを推進」「提案を進める」のような実行アドバイスではなく、「期日・件数・対象名の確認」に留める。";
        $lines[] = "- 期限切れ間近のSES契約があれば最優先で取り上げる。";
        $lines[] = "- 出力は「1. ◯◯◯」「2. ◯◯◯」のように番号付き行のみ。行間に空行は入れない。前置き・後書き不要。";
        $lines[] = "";
        $lines[] = "## 状況サマリ（前日受信メール × 過去7日マッチ）";

        if ($prj && $prj['count'] > 0) {
            $lines[] = "- 有効と思われる案件メール: {$prj['count']}件";
            foreach ($prj['list'] as $p) {
                $price = $p['unit_price_max'] ? " ({$p['unit_price_max']}万)" : '';
                $sub   = $p['customer_name'] ? " / {$p['customer_name']}" : '';
                $lines[] = "  - {$p['title']}{$sub}{$price}";
                foreach ($p['matches'] as $m) {
                    $mPrice = $m['unit_price_max'] ? " ({$m['unit_price_max']}万)" : '';
                    $aff    = $m['affiliation'] ? " / {$m['affiliation']}" : '';
                    $lines[] = "      └ マッチ{$m['score']} {$m['name']}{$aff}{$mPrice}";
                }
            }
        }
        if ($eng && $eng['count'] > 0) {
            $lines[] = "- 有効と思われる技術者メール: {$eng['count']}件";
            foreach ($eng['list'] as $e) {
                $price = $e['unit_price_max'] ? " ({$e['unit_price_max']}万)" : '';
                $aff   = $e['affiliation'] ? " / {$e['affiliation']}" : '';
                $lines[] = "  - {$e['name']}{$aff}{$price} {$e['skills_summary']}";
                foreach ($e['matches'] as $m) {
                    $mPrice = $m['unit_price_max'] ? " ({$m['unit_price_max']}万)" : '';
                    $sub    = $m['customer_name'] ? " / {$m['customer_name']}" : '';
                    $lines[] = "      └ マッチ{$m['score']} {$m['title']}{$sub}{$mPrice}";
                }
            }
        }
        if ($exp && $exp['count'] > 0) {
            $lines[] = "- 契約期限まで30日以内: {$exp['count']}件";
            foreach ($exp['list'] as $c) {
                $lines[] = "  - あと{$c['days_left']}日 ({$c['end_date']}) {$c['engineer_name']} / " . ($c['customer_name'] ?? '?');
            }
        }

        return implode("\n", $lines);
    }

    /** skills(JSON) を 1 行のサマリ文字列に */
    private function summarizeSkills(mixed $skills): string
    {
        if (!is_array($skills) || empty($skills)) return '';
        $head = array_slice($skills, 0, 3);
        return implode(' / ', array_map('strval', $head));
    }
}
