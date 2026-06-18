<?php

namespace App\Console\Commands;

use App\Services\KagoyaMailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * DB で分類済みの Kagoya 取込メールを Kagoya IMAP サーバー上から削除する（\Deleted + EXPUNGE）。
 *
 * 旧 gmail:trash-classified（Gmail API 廃止に伴い停止）の後継。容量超過バウンスの根本対策。
 * 既定は DRY-RUN（削除せず件数のみ）。実削除は --execute（+ 非対話は --force）。
 * 削除対象は DB 側で確定（分類済み・取込済み imap-UID のみ）するため、未取込/別経路を消すことは構造的に起きない。
 *
 * 例:
 *   php artisan kagoya:trash-classified                              # DRY-RUN（件数のみ）
 *   php artisan kagoya:trash-classified --execute --limit=50         # 少量で実削除（初回ランプ用）
 *   php artisan kagoya:trash-classified --execute --force --limit=5000  # スケジュール用
 */
class KagoyaTrashClassified extends Command
{
    protected $signature = 'kagoya:trash-classified
        {--min-age=15 : この分数より前に取込まれたメールのみ対象（同期取りこぼし防止の安全マージン）}
        {--limit=500 : 1回の実行で削除する最大UID件数}
        {--execute : 実際に削除する（未指定は DRY-RUN）}
        {--force : 確認プロンプトをスキップ（非対話/スケジュール用）}';

    protected $description = 'DBで分類済みのKagoya IMAPメールをサーバー上で削除（\\Deleted + EXPUNGE）して容量を回収する';

    public function handle(KagoyaMailService $mail): int
    {
        $minAge  = (int) $this->option('min-age');
        $limit   = (int) $this->option('limit');
        $execute = (bool) $this->option('execute');

        $this->info("対象: classified_at 設定済みの取込済み(imap-) メール / created_at < now-{$minAge}分");
        $this->info($execute ? "モード: 実削除 (limit={$limit})" : 'モード: DRY-RUN (削除しません)');

        if ($execute && !$this->option('force')) {
            if (!$this->confirm("Kagoya サーバーから最大 {$limit} 件を完全削除（EXPUNGE・不可逆）します。続行しますか?", false)) {
                $this->warn('中止しました');
                return self::SUCCESS;
            }
        }

        try {
            $stats = $mail->purgeClassifiedMail($minAge, $limit, $execute);
        } catch (\Throwable $e) {
            $this->error('失敗: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->table(
            ['key', 'value'],
            collect($stats)->map(fn ($v, $k) => [$k, is_null($v) ? '-' : (is_bool($v) ? ($v ? 'true' : 'false') : $v)])->values()->all()
        );

        if (!$execute) {
            $this->info("DRY-RUN: 削除可能 {$stats['deletable']} 件（DB分類済 {$stats['db_target']} / サーバー実在 {$stats['server_present']}）。実削除は --execute（非対話は --force）を付与。");
        } else {
            Log::info('kagoya:trash-classified 完了', [
                'db_target' => $stats['db_target'],
                'flagged'   => $stats['flagged'],
                'expunged'  => $stats['expunged'],
            ]);
            $this->info("削除完了: flagged={$stats['flagged']} expunged={$stats['expunged']} (EXISTS {$stats['exists_before']} → {$stats['exists_after']})。残りがあれば再実行で続行。");
        }

        return self::SUCCESS;
    }
}
