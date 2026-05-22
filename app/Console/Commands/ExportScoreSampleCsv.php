<?php

namespace App\Console\Commands;

use App\Models\EngineerMailSource;
use App\Models\ProjectMailSource;
use App\Services\EngineerMailMatchingService;
use App\Services\ProjectMailMatchingService;
use Illuminate\Console\Command;

/**
 * 営業協議 (docs/560) 用に、直近の案件/技術者メールの
 * 品質スコア + マッチスコア + 内訳を CSV 出力する。
 *
 * 使い方:
 *   php artisan export:score-sample --tenant=1 --limit=20 --out=/tmp/score_sample.csv
 *
 * マッチスコア:
 *   - 案件メール (PMS): 登録済 Engineer との対戦で最高スコア
 *   - 技術者メール (EMS): 直近 PMS プール (status != excluded, 最大 200 件) との対戦で最高スコア
 */
class ExportScoreSampleCsv extends Command
{
    protected $signature = 'export:score-sample
                            {--tenant=1 : tenant_id}
                            {--limit=20 : 各メール種別の抽出件数}
                            {--pms-pool=200 : EMS のマッチ対象とする PMS プール上限}
                            {--out=/tmp/score_sample.csv : 出力先 (絶対パス)}';

    protected $description = '直近の案件/技術者メールの品質/マッチスコア + 内訳を CSV 出力 (docs/560 営業協議用)';

    public function handle(
        ProjectMailMatchingService $pmsMatching,
        EngineerMailMatchingService $emsMatching,
    ): int {
        $tenantId = (int) $this->option('tenant');
        $limit    = (int) $this->option('limit');
        $poolMax  = (int) $this->option('pms-pool');
        $out      = (string) $this->option('out');

        $header = [
            '種別', 'source_id', 'ステータス',
            '品質スコア', '品質スコア内訳',
            'マッチスコア', 'マッチスコア内訳(配点)', 'マッチスコア内訳(理由)', 'マッチ相手',
            '件名', '送信元会社', '送信者名', '送信元メール', '受信日時',
        ];

        // ── PMS 抽出 ─────────────────────────────────
        $pmsList = ProjectMailSource::with('email')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderByDesc('received_at')
            ->limit($limit)
            ->get();

        $rows = [$header];

        $this->info("PMS {$pmsList->count()} 件を処理中...");
        $bar = $this->output->createProgressBar($pmsList->count());
        foreach ($pmsList as $pms) {
            $top = $pmsMatching->matchEngineers($pms, 1)->first();
            $rows[] = [
                '案件',
                $pms->id,
                $this->statusJa($pms->status),
                $pms->score,
                $this->formatReasons($pms->score_reasons),
                $top['score'] ?? '',
                isset($top['breakdown']) ? $this->formatBreakdown($top['breakdown']) : '',
                isset($top['reasons']) ? $this->formatReasons($top['reasons']) : '',
                $top['engineer']->name ?? '',
                $pms->title ?? '',
                $pms->customer_name ?? '',
                $pms->email->from_name ?? '',
                $pms->email->from_address ?? '',
                (string) $pms->received_at,
            ];
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        // ── EMS 抽出 ─────────────────────────────────
        $emsList = EngineerMailSource::with('email')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderByDesc('received_at')
            ->limit($limit)
            ->get();

        // 対戦相手 PMS プール (直近 N 件, excluded は除外)
        $pmsPool = ProjectMailSource::with([])
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'excluded')
            ->orderByDesc('received_at')
            ->limit($poolMax)
            ->get();

        $this->info("EMS {$emsList->count()} 件を処理中 (PMS プール {$pmsPool->count()} 件と総当たり)...");
        $bar = $this->output->createProgressBar($emsList->count());
        foreach ($emsList as $ems) {
            $best = null;
            foreach ($pmsPool as $pms) {
                $result = $emsMatching->score($ems, $pms);
                if ($best === null || $result['score'] > $best['score']) {
                    $best = $result + ['pms_title' => $pms->title, 'pms_id' => $pms->id];
                }
            }
            $rows[] = [
                '技術者',
                $ems->id,
                $this->statusJa($ems->status),
                $ems->score,
                $this->formatReasons($ems->score_reasons),
                $best['score'] ?? '',
                isset($best['breakdown']) ? $this->formatBreakdown($best['breakdown']) : '',
                isset($best['reasons']) ? $this->formatReasons($best['reasons']) : '',
                isset($best['pms_title']) ? "#{$best['pms_id']} " . $best['pms_title'] : '',
                $ems->email->subject ?? '',
                $ems->affiliation ?? '',
                $ems->email->from_name ?? '',
                $ems->email->from_address ?? '',
                (string) $ems->received_at,
            ];
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        // ── CSV 書き出し (Excel 用 BOM 付) ─────────────
        $fp = fopen($out, 'w');
        if ($fp === false) {
            $this->error("ファイルを開けません: {$out}");
            return self::FAILURE;
        }
        fwrite($fp, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        $this->info("出力完了: {$out} (" . count($rows) - 1 . " 行)");
        return self::SUCCESS;
    }

    private function formatReasons($reasons): string
    {
        if (is_string($reasons)) {
            $decoded = json_decode($reasons, true);
            if (is_array($decoded)) $reasons = $decoded;
        }
        if (!is_array($reasons)) return '';
        return implode(' | ', array_map(fn($r) => $this->reasonJa((string) $r), $reasons));
    }

    private function formatBreakdown(array $bd): string
    {
        $map = [
            'requirements' => '必須条件',
            'skills'       => 'スキル',
            'conditions'   => '条件',
            'availability' => '稼働',
            'track_record' => '実績',
        ];
        $parts = [];
        foreach ($bd as $k => $v) {
            $label = $map[$k] ?? $k;
            $parts[] = "{$label}:{$v}";
        }
        return implode(' / ', $parts);
    }

    private function statusJa(?string $status): string
    {
        return match ($status) {
            'new'      => '新規',
            'review'   => '要確認',
            'excluded' => '除外',
            default    => (string) $status,
        };
    }

    /**
     * 品質スコアの reason キーを和名に変換。
     * 形式は "key:value" または "key" (例: price_concrete) または
     * "domain:xxx:+20(...)" のようにコロンが複数あるパターンもある。
     */
    private function reasonJa(string $reason): string
    {
        // 単体キー (コロン無し) は固定マップ
        $singleMap = [
            'price_concrete'      => '単価具体性',
            'has_attachment'      => '添付ファイルあり',
            'no_unit_price'       => '希望単価なし',
            'unit_price_too_low'  => '希望単価35万未満',
            'excluded'            => '除外判定',
        ];
        if (isset($singleMap[$reason])) {
            return $singleMap[$reason];
        }

        // domain:xxx:+20(...) は domain プレフィックスのまま値を保つ
        if (strpos($reason, 'domain:') === 0) {
            return 'ドメイン信頼度:' . substr($reason, strlen('domain:'));
        }

        // prefix:value 形式
        $prefixMap = [
            'project_a'     => '案件確度A',
            'project_b'     => '案件確度B',
            'lang'          => '技術1',
            'lang2'         => '技術2',
            'infra'         => '技術(インフラ)',
            'db'            => '技術(DB)',
            'location'      => '勤務地',
            'process'       => '工程',
            'timing'        => '稼働期間',
            'penalty_vague' => 'ペナルティ(単価曖昧)',
            'penalty_chain' => 'ペナルティ(高次商流)',
            'engineer_kw'   => '明示ワード',
            'availability'  => '稼働条件',
            'tech'          => '技術',
            'affiliation'   => '所属区分',
        ];
        $pos = strpos($reason, ':');
        if ($pos !== false) {
            $prefix = substr($reason, 0, $pos);
            $value  = substr($reason, $pos + 1);
            if (isset($prefixMap[$prefix])) {
                return $prefixMap[$prefix] . ':' . $value;
            }
        }
        return $reason; // 想定外はそのまま
    }
}
