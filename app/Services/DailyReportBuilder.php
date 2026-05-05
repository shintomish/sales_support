<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DeliverySendHistory;
use App\Models\Email;
use App\Models\EngineerMailSource;
use App\Models\SesContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 朝の日次レポート 組立サービス
 *
 * パイプライン:
 *   [1] 5項目データ収集（emails / engineer_mail_sources / delivery_send_histories / SES契約期限）
 *   [2] 品質ゲート: 0件セクションは除外
 *   [3] Haiku で「優先順位付き今日のアクション」サマリー（要対応 >= 1 件のとき）
 *
 * テナントスコープ: tenant_id を指定して呼ぶこと。Auth context が無いコマンド実行で動かすため。
 */
class DailyReportBuilder
{
    public function __construct(
        private readonly ClaudeService $claude,
    ) {}

    /**
     * テナントごとのレポート用データを返す。
     *
     * @return array{
     *   tenant_id: int,
     *   target_date: string,
     *   sections: array<string, array<string,mixed>>,
     *   total_action_items: int,
     *   ai_summary: ?string
     * }
     */
    public function build(int $tenantId): array
    {
        $today     = Carbon::today();
        $yesterday = $today->copy()->subDay();

        $sections = [];

        $sections['inbox']    = $this->collectInbox($tenantId, $yesterday, $today);
        $sections['matches']  = $this->collectNewMatches($tenantId, $yesterday);
        $sections['delivery'] = $this->collectDeliveryStats($tenantId, $yesterday, $today);
        $sections['expiring'] = $this->collectExpiringContracts($tenantId, 30);

        // 品質ゲート: 0 件セクションは除外
        $sections = array_filter($sections, fn ($s) => ($s['count'] ?? 0) > 0);

        $totalActionItems = ($sections['matches']['count']  ?? 0)
                          + ($sections['expiring']['count'] ?? 0);

        $aiSummary = null;
        if ($totalActionItems >= 1) {
            $aiSummary = $this->summarizeWithHaiku($sections);
        }

        return [
            'tenant_id'          => $tenantId,
            'target_date'        => $yesterday->toDateString(),
            'sections'           => $sections,
            'total_action_items' => $totalActionItems,
            'ai_summary'         => $aiSummary,
        ];
    }

    /** [1] 受信メール件数（昨日24h、分類別） */
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
            'count'      => $total,
            'engineer'   => (int) ($rows['engineer'] ?? 0),
            'project'    => (int) ($rows['project']  ?? 0),
            'other'      => (int) ($rows['other']    ?? 0),
        ];
    }

    /** [2] 新規SESマッチング候補（直近24h で status=review、上位5件＋件数） */
    private function collectNewMatches(int $tenantId, Carbon $from): array
    {
        $query = EngineerMailSource::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'review')
            ->where('created_at', '>=', $from);

        $count = (clone $query)->count();
        $top   = (clone $query)
            ->orderByDesc('score')
            ->orderByDesc('created_at')
            ->take(5)
            ->get(['id', 'name', 'score', 'unit_price_max', 'skills', 'created_at']);

        return [
            'count' => $count,
            'top'   => $top->map(fn ($m) => [
                'id'              => $m->id,
                'name'            => $m->name ?: '（名前未取得）',
                'score'           => (int) $m->score,
                'unit_price_max'  => $m->unit_price_max,
                'skills_summary'  => $this->summarizeSkills($m->skills),
                'received_at'     => optional($m->created_at)->format('n/j H:i'),
            ])->toArray(),
        ];
    }

    /** [3] 提案メール送信実績（昨日24h、status別） */
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

    /** [4] 期限切れ間近のSES契約（今後 N 日以内、最大20件） */
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

        // 取引先名を deal_id 経由で一括取得
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
                $days = (int) $today->diffInDays($end, false);
                $row = $customers->get($c->deal_id);
                return [
                    'id'             => $c->id,
                    'deal_id'        => $c->deal_id,
                    'engineer_name'  => $c->engineer_name ?: '（未設定）',
                    'customer_name'  => $row->customer_name ?? null,
                    'deal_title'     => $row->deal_title ?? null,
                    'end_date'       => $end->toDateString(),
                    'days_left'      => $days,
                ];
            })->toArray(),
        ];
    }

    /**
     * Claude Haiku で「今日のアクション」を3つ生成。
     * API失敗時は null を返してレポートは続行（落ちないこと）。
     */
    private function summarizeWithHaiku(array $sections): ?string
    {
        $prompt = $this->buildSummaryPrompt($sections);
        try {
            $text = $this->claude->ask($prompt);
            return trim($text);
        } catch (Throwable $e) {
            Log::warning('DailyReport Haiku summary failed', ['err' => $e->getMessage()]);
            return null;
        }
    }

    private function buildSummaryPrompt(array $sections): string
    {
        $matches  = $sections['matches']  ?? null;
        $expiring = $sections['expiring'] ?? null;

        $lines = [];
        $lines[] = "あなたはSES企業の営業マネージャーです。以下の状況を踏まえ、今日取るべきアクションを優先順位順に最大3つ、各1〜2行で簡潔に箇条書きしてください。";
        $lines[] = "出力フォーマット: 「1. ◯◯◯」「2. ◯◯◯」のように番号付きの行のみ（前置き・後書き不要）。";
        $lines[] = "";
        $lines[] = "## 状況サマリ";

        if ($matches && $matches['count'] > 0) {
            $lines[] = "- 新規SES候補（要確認）: {$matches['count']}件";
            foreach ($matches['top'] as $m) {
                $price = $m['unit_price_max'] ? "({$m['unit_price_max']}万)" : '';
                $lines[] = "  - スコア{$m['score']} {$m['name']} {$price} {$m['skills_summary']}";
            }
        }

        if ($expiring && $expiring['count'] > 0) {
            $lines[] = "- 契約期限まで30日以内: {$expiring['count']}件";
            foreach ($expiring['list'] as $c) {
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
