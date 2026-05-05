<?php

namespace App\Services;

use App\Models\DeliverySendHistory;
use App\Models\Email;
use App\Models\EngineerMailSource;
use App\Models\ProjectMailSource;
use App\Models\SesContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 朝の日次レポート 組立サービス
 *
 * パイプライン:
 *   [1] データ収集（emails / engineer・project mail sources / delivery / SES契約）
 *   [2] 品質ゲート: 0件セクションは除外
 *   [3] Haiku で「優先順位付き今日のアクション」サマリー（要対応 >= 1 件のとき）
 *
 * 「新着SES」は score >= 80（既定）で絞り込み、技術者/案件 を別々に集計する。
 * テナントスコープ: tenant_id を指定して呼ぶ（Auth context が無いコマンド実行で動かすため）。
 */
class DailyReportBuilder
{
    private const SCORE_THRESHOLD = 70;

    public function __construct(
        private readonly ClaudeService $claude,
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
        $today     = Carbon::today();
        $yesterday = $today->copy()->subDay();

        $sections = [];

        $sections['inbox']            = $this->collectInbox($tenantId, $yesterday, $today);
        $sections['engineer_matches'] = $this->collectNewMatches($tenantId, EngineerMailSource::class, $yesterday);
        $sections['project_matches']  = $this->collectNewMatches($tenantId, ProjectMailSource::class, $yesterday);
        $sections['delivery']         = $this->collectDeliveryStats($tenantId, $yesterday, $today);
        $sections['expiring']         = $this->collectExpiringContracts($tenantId, 30);

        // 品質ゲート: 0 件セクションは除外
        $sections = array_filter($sections, fn ($s) => ($s['count'] ?? 0) > 0);

        $actionTotal = ($sections['engineer_matches']['count'] ?? 0)
                     + ($sections['project_matches']['count']  ?? 0)
                     + ($sections['expiring']['count']         ?? 0);

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
        $rows = Email::withoutGlobalScopes()
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
     * [2/3] 新着スコア上位 (engineer または project mail source)
     *  - 直近24h かつ score >= SCORE_THRESHOLD
     *  - 同一案件・同一技術者の重複は (title|customer) / (name|skills) でユニーク化
     *  - ユニーク後の件数と上位5件を返す
     *
     * @param class-string $modelClass EngineerMailSource::class | ProjectMailSource::class
     */
    private function collectNewMatches(int $tenantId, string $modelClass, Carbon $from): array
    {
        $isEngineer = $modelClass === EngineerMailSource::class;

        // ユニーク化のためにある程度多めに取得してから重複排除
        $rows = $modelClass::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('score', '>=', self::SCORE_THRESHOLD)
            ->where('created_at', '>=', $from)
            ->orderByDesc('score')
            ->orderByDesc('created_at')
            ->take(200)
            ->get();

        // 重複排除キー
        $unique = $rows->unique(function ($m) use ($isEngineer) {
            if ($isEngineer) {
                $skillsKey = is_array($m->skills) ? implode(',', $m->skills) : '';
                return ($m->name ?: '?') . '|' . $skillsKey;
            }
            return ($m->title ?: '?') . '|' . ($m->customer_name ?: '?');
        })->values();

        $count = $unique->count();
        $top   = $unique->take(5);

        return [
            'count' => $count,
            'top'   => $top->map(function ($m) use ($isEngineer) {
                if ($isEngineer) {
                    return [
                        'id'             => $m->id,
                        'kind'           => 'engineer',
                        'title'          => $m->name ?: '（名前未取得）',
                        'sub'            => null,
                        'score'          => (int) $m->score,
                        'unit_price_max' => $m->unit_price_max,
                        'skills_summary' => $this->summarizeSkills($m->skills),
                        'received_at'    => optional($m->created_at)->format('n/j H:i'),
                    ];
                }
                return [
                    'id'             => $m->id,
                    'kind'           => 'project',
                    'title'          => $m->title ?: '（件名未取得）',
                    'sub'            => $m->customer_name,
                    'score'          => (int) $m->score,
                    'unit_price_max' => $m->unit_price_max,
                    'skills_summary' => $this->summarizeSkills($m->required_skills ?? []),
                    'received_at'    => optional($m->created_at)->format('n/j H:i'),
                ];
            })->toArray(),
        ];
    }

    /** [4] 提案メール送信実績（対象日24h、status別） */
    private function collectDeliveryStats(int $tenantId, Carbon $from, Carbon $to): array
    {
        $rows = DeliverySendHistory::withoutGlobalScopes()
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
        $today = Carbon::today();
        $limit = $today->copy()->addDays($days);

        $contracts = SesContract::withoutGlobalScopes()
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
        $eng  = $sections['engineer_matches'] ?? null;
        $prj  = $sections['project_matches']  ?? null;
        $exp  = $sections['expiring']         ?? null;

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
        $lines[] = "## 状況サマリ（直近24h、重複排除済み）";

        if ($eng && $eng['count'] > 0) {
            $lines[] = "- 新着 技術者(スコア70+ ユニーク): {$eng['count']}件";
            foreach ($eng['top'] as $m) {
                $price = $m['unit_price_max'] ? " ({$m['unit_price_max']}万)" : '';
                $lines[] = "  - スコア{$m['score']} {$m['title']}{$price} {$m['skills_summary']}";
            }
        }
        if ($prj && $prj['count'] > 0) {
            $lines[] = "- 新着 案件(スコア70+ ユニーク): {$prj['count']}件";
            foreach ($prj['top'] as $m) {
                $price = $m['unit_price_max'] ? " ({$m['unit_price_max']}万)" : '';
                $sub   = $m['sub'] ? " / {$m['sub']}" : '';
                $lines[] = "  - スコア{$m['score']} {$m['title']}{$sub}{$price} {$m['skills_summary']}";
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
