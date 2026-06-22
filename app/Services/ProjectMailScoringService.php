<?php

namespace App\Services;

use App\Models\Email;
use App\Models\ProjectMailSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 案件メール判定・スコアリング＋正規表現抽出サービス
 *
 * ① 除外判定 → ② 強ワード判定 → ③ スコア判定
 * スコア確定後に正規表現で案件情報を抽出して保存する。
 * 将来 AI 差し替え時は engine='ai' で同一 IF を使う。
 */
class ProjectMailScoringService
{
    private const URL_PATTERN = '/https?:\/\/[^\s\x{3000}"\'<>「」【】）\)]+/u';

    /**
     * ドメインボーナス集計のランタイムキャッシュ（テナント×ドメイン単位）。
     * rescoreAll() で N 件処理する際、同一ドメインの集計クエリを毎回流すのを防ぐ。
     */
    private array $domainBonusCache = [];

    // ── ① 除外ワード ──────────────────────────────────────

    private const EXCLUDE_SUBJECT = [
        '配信停止', 'メルマガ', '広告', '請求書', 'お支払い',
        '正社員募集', '中途採用', 'ご挨拶', 'お知らせ',
    ];

    private const EXCLUDE_FROM = ['no-reply', 'noreply'];

    // 自社ドメイン（自社・当社営業担当のメールは案件対象外）+ 営業判断で受信不要とした取引先ドメイン (2026-06-22 新冨さん指示)
    private const EXCLUDE_DOMAIN = ['aizen-sol.co.jp', 'b-tm.co.jp', 'careerbeat.jp'];

    // domain bonus 集計の対象外ドメイン（フォームサービス等・実送信者と無関係）
    private const DOMAIN_BONUS_SKIP = ['smoothcontact.com', 'gmail.com', 'yahoo.co.jp', 'hotmail.com'];

    // ── ② スコア辞書（max 85点設計）────────────────────────
    //
    // [A] 案件確度A (+15): 明示的な案件紹介ワード
    // [B] 案件確度B (+10): 条件明示ワード（稼働・期間）
    // [C] 技術スタック (max 20): 言語+インフラ+DB
    // [D] 単価具体性  (+15): XX万 という数字
    // [E] 勤務地      (+10): 都市名
    // [F] 工程        (max 10): 上流>開発>テスト
    // [G] 稼働・期間  (+5): 即日/長期/人月
    // ペナルティ: 曖昧単価(-10) / 高次商流(-10)
    // 合計上限: 85点

    // [A] 明示的案件ワード
    private const PROJECT_A = [
        '案件ご紹介', '要員ご紹介', '技術者ご紹介', '案件情報',
        '要員紹介', '技術者紹介', '技術者募集', 'エンジニア募集',
        'SE募集', 'PG募集',
    ];

    // [B] 条件提示ワード
    private const PROJECT_B = [
        '稼働期間', '稼働開始', '開始時期', '参画時期', '稼働率',
        '単価', '月額', '工数',
    ];

    // [C] 技術スタック
    private const TECH_LANG = [
        'Java', 'Spring', 'SpringBoot', 'PHP', 'Laravel',
        'Python', 'Django', 'Flask', 'C#', '.NET',
        'JavaScript', 'TypeScript', 'React', 'Vue', 'Angular',
        'Ruby', 'Rails', 'Go', 'Golang', 'Swift', 'Kotlin',
    ];
    private const TECH_INFRA = [
        'AWS', 'EC2', 'RDS', 'S3', 'Lambda',
        'Azure', 'GCP', 'Docker', 'Kubernetes', 'Linux',
    ];
    private const TECH_DB = [
        'MySQL', 'PostgreSQL', 'Oracle', 'SQLServer', 'MongoDB', 'Redis',
    ];

    // [F] 工程
    private const PROCESS_UPPER = ['要件定義', '基本設計', '詳細設計'];
    private const PROCESS_DEV   = ['開発', '実装', '製造'];
    private const PROCESS_OTHER = ['テスト', '保守', '運用'];

    // [E] 勤務地
    private const LOCATION_KW = [
        '東京', '大阪', '名古屋', '横浜', '品川', '渋谷', '新宿',
        '福岡', '仙台', '札幌', '在宅',
    ];

    // ペナルティ
    private const PENALTY_VAGUE = ['スキル見合い', '応相談'];
    private const PENALTY_CHAIN = ['4次', '5次', '6次', '7次', '8次'];  // 高次商流

    private const SCORE_OK     = 60;
    private const SCORE_REVIEW = 40;

    // ── 公開メソッド ──────────────────────────────────────

    /**
     * 未処理メールを一括スコアリング
     *
     * @param int|null $limit        処理件数の上限
     * @param int|null $lookbackDays 何日前までのメールを対象とするか（既定1日／null=全期間）
     *
     * 既定で1日の窓を設けることで、毎15分のスケジューラ実行時に
     * 全期間（数千件）を NOT EXISTS で再スキャンするのを防止する。
     * 数日分の取りこぼしを再処理したい場合は手動で $lookbackDays を指定する。
     */
    public function scorePending(?int $limit = null, ?int $lookbackDays = 1): int
    {
        $query = Email::where('category', 'project')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                  ->from('project_mail_sources')
                  ->whereColumn('project_mail_sources.email_id', 'emails.id');
            })
            ->when($lookbackDays !== null, fn($q) => $q->where('received_at', '>=', now()->subDays($lookbackDays)))
            ->orderByDesc('received_at');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $count = 0;
        foreach ($query->get() as $email) {
            try {
                $this->score($email);
                $count++;
            } catch (\Throwable $e) {
                Log::error("[ProjectMailScoring] email_id={$email->id} 失敗: " . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * 既存レコードを全件再スコアリング＋再抽出（バッチ処理対応）
     *
     * @param int|null $tenantId Schedule tick(Auth無し)から呼ぶ時に明示スコープ。
     *                           null の場合は GlobalScope(Auth)に委ねる。
     */
    /**
     * Shadow rescore — UPDATE せずに score/status 変動だけ集計する pre-deploy 検証。
     *
     * 用途 (docs/730 #1 / 5/25 営業部決定の本番投入前):
     *   - ルール変更ブランチで実行 → どれだけの行が status を変えるか定量化
     *   - 60点(SCORE_OK) / 40点(SCORE_REVIEW) 跨ぎ件数を可視化
     *   - 半日かかる本番 rescore 失敗からの後追い修正を回避
     *
     * 性能: extract() を呼ばないため通常 rescore より大幅高速 (本文 TOAST 読まない)。
     *
     * @return array{
     *   total:int, unchanged:int, changed_score:int, changed_status:int,
     *   crossed_review_threshold:int, crossed_ok_threshold:int,
     *   transitions: array<string,int>,
     *   sample_changes: array<int, array{pms_id:int, old_score:int, new_score:int, old_status:?string, new_status:string}>
     * }
     */
    public function rescoreAllShadow(?int $limit = null, int $offset = 0, ?int $tenantId = null): array
    {
        $query = ProjectMailSource::with(['email' => fn($q) => $q->select('id', 'subject', 'body_text', 'body_html', 'from_address')])
            ->whereNotNull('email_id')
            ->orderBy('id');
        if ($tenantId !== null) $query->where('tenant_id', $tenantId);
        if ($offset > 0)        $query->skip($offset);
        if ($limit !== null)    $query->limit($limit);

        $stats = [
            'total' => 0, 'unchanged' => 0, 'changed_score' => 0, 'changed_status' => 0,
            'crossed_review_threshold' => 0, 'crossed_ok_threshold' => 0,
            'transitions' => [],
            'sample_changes' => [],
        ];

        // 全テナントを横断するなら domain bonus prewarm (rescoreAll 同等)
        $tenantsToWarm = $tenantId !== null
            ? [$tenantId]
            : ProjectMailSource::whereNotNull('email_id')
                ->select('tenant_id')->distinct()->pluck('tenant_id')->all();
        foreach ($tenantsToWarm as $tid) {
            $this->prewarmDomainBonusCache((int) $tid);
        }

        foreach ($query->cursor() as $pms) {
            if (!$pms->email) continue;
            try {
                $email   = $pms->email;
                $subject = $email->subject ?? '';
                $body    = $email->body_text ?? strip_tags($email->body_html ?? '');
                $from    = $email->from_address ?? '';
                $text    = $subject . "\n" . $body;

                $oldScore  = (int) $pms->score;
                $oldStatus = $pms->status;

                if ($this->isExcluded($subject, $from)) {
                    $newScore  = 0;
                    $newStatus = 'excluded';
                } elseif (trim($body) === '') {
                    // 本文が retention(30日 CleanupEmails) で purge 済み。件名のみの再スコアは
                    // 破壊的なので保存値を温存する（差分なし=unchanged 扱い）。
                    $newScore  = $oldScore;
                    $newStatus = $oldStatus;
                } else {
                    [$newScore, $_] = $this->calcScore($text);
                    $domainData = $this->domainBonus($from, $pms->tenant_id);
                    $newScore  += $domainData['bonus'];
                    $newScore   = max(0, min(100, $newScore));
                    $newStatus  = match (true) {
                        $newScore >= self::SCORE_OK     => 'new',
                        $newScore >= self::SCORE_REVIEW => 'review',
                        default                         => 'excluded',
                    };
                }

                $stats['total']++;
                $scoreChanged  = $oldScore !== $newScore;
                $statusChanged = $oldStatus !== $newStatus;

                if (!$scoreChanged && !$statusChanged) {
                    $stats['unchanged']++;
                    continue;
                }
                if ($scoreChanged)  $stats['changed_score']++;
                if ($statusChanged) {
                    $stats['changed_status']++;
                    $key = ($oldStatus ?? '(null)') . '->' . $newStatus;
                    $stats['transitions'][$key] = ($stats['transitions'][$key] ?? 0) + 1;
                }
                if ($this->crossedThreshold($oldScore, $newScore, self::SCORE_REVIEW)) {
                    $stats['crossed_review_threshold']++;
                }
                if ($this->crossedThreshold($oldScore, $newScore, self::SCORE_OK)) {
                    $stats['crossed_ok_threshold']++;
                }
                if (count($stats['sample_changes']) < 20) {
                    $stats['sample_changes'][] = [
                        'pms_id'     => $pms->id,
                        'old_score'  => $oldScore,
                        'new_score'  => $newScore,
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus,
                    ];
                }
            } catch (\Throwable $e) {
                Log::error("[ProjectMailRescoreShadow] pms_id={$pms->id} 失敗: " . $e->getMessage());
            }
        }
        return $stats;
    }

    /** old/new が threshold を跨いだか (どちらの方向でも true) */
    private function crossedThreshold(int $oldScore, int $newScore, int $threshold): bool
    {
        return ($oldScore < $threshold && $newScore >= $threshold)
            || ($oldScore >= $threshold && $newScore < $threshold);
    }

    public function rescoreAll(?int $limit = null, int $offset = 0, ?int $tenantId = null): int
    {
        $query = ProjectMailSource::with('email')
            ->whereNotNull('email_id')
            ->orderBy('id');
        if ($tenantId !== null) $query->where('tenant_id', $tenantId);
        if ($offset > 0) $query->skip($offset);
        if ($limit !== null) $query->limit($limit);

        $count = 0;
        // batchSize 件分の UPDATE を 1 トランザクションにまとめて fsync 回数を削減
        // (Sentry 2026-05-23 N+1 警告対応)。1件単位の例外は内部 try/catch で握りつぶす。
        DB::transaction(function () use ($query, &$count) {
            foreach ($query->get() as $pms) {
                if (!$pms->email) continue;
                try {
                    $email   = $pms->email;
                    $subject = $email->subject ?? '';
                    $body    = $email->body_text ?? strip_tags($email->body_html ?? '');
                    $from    = $email->from_address ?? '';
                    $text    = $subject . "\n" . $body;

                    if ($this->isExcluded($subject, $from)) {
                        $pms->update(['score' => 0, 'score_reasons' => ['excluded'], 'status' => 'excluded']);
                    } elseif (trim($body) === '') {
                        // 本文が retention(30日 CleanupEmails) で purge 済み。件名のみの再スコアは
                        // 破壊的なので保存値を温存する（update せずスキップ）。
                    } else {
                        [$score, $reasons] = $this->calcScore($text);
                        $domainData = $this->domainBonus($from, $pms->tenant_id);
                        if ($domainData['bonus'] !== 0) {
                            $score    += $domainData['bonus'];
                            $sign      = $domainData['bonus'] > 0 ? '+' : '';
                            $pct       = round($domainData['rate'] * 100);
                            $reasons[] = "domain:{$domainData['domain']}:{$sign}{$domainData['bonus']}({$pct}%/{$domainData['sample']}件)";
                        }
                        $score     = max(0, min(100, $score));
                        $extracted = $this->extract($email);
                        $status = match(true) {
                            $score >= self::SCORE_OK     => 'new',
                            $score >= self::SCORE_REVIEW => 'review',
                            default                      => 'excluded',
                        };
                        $pms->update(array_merge($this->sanitizeExtracted($extracted), [
                            'score'         => $score,
                            'score_reasons' => $reasons,
                            'engine'        => 'rule',
                            'status'        => $status,
                        ]));
                    }
                    $count++;
                } catch (\Throwable $e) {
                    Log::error("[ProjectMailRescore] pms_id={$pms->id} 失敗: " . $e->getMessage());
                }
            }
        });
        return $count;
    }

    /**
     * ドメイン信頼度補正だけを既存スコアに軽量反映する（営業打ち合わせ 2026-05-25 §4.6 ケースC）。
     *
     * 「全件再スコア」ボタンを営業 UI から外した代替。提案実績の蓄積で経時変動する
     * domain bonus を既存スコアへ反映する。全件再スコア（score+抽出を全再計算）と違い:
     *   - extract() を呼ばない＝手編集した顧客名・単価等の抽出情報を壊さない
     *   - calcScore() も呼ばず、本文(body_text=TOAST)を読まない＝Disk IO 最小
     * 仕組み: score_reasons の旧 `domain:...` 行から旧補正値を読み、
     *   新スコア = clamp(現スコア − 旧補正 + 現 domainBonus()) として差分反映する。
     * clamp 域（極端値）でのみ基礎点の誤差が出るが、しきい値(40/60)を跨がないため
     * status(new/review/excluded) 判定には影響しない。
     * 値に変化が無い行は UPDATE しない（Disk IO 抑制 — 夜次運用では大半が不変）。
     * isExcluded 由来の除外（score_reasons=['excluded']）は subject/from ベースで
     * domain bonus と無関係なので据え置く。
     *
     * @return int 実際にスコア/状態が変化して更新した件数
     */
    public function refreshDomainBonus(?int $tenantId = null, ?int $limit = null, int $chunkSize = 500): int
    {
        // from_address だけ必要（body_text=TOAST は読まない＝Disk IO 抑制）。
        $query = ProjectMailSource::with(['email' => fn($q) => $q->select('id', 'from_address')])
            ->whereNotNull('email_id');
        if ($tenantId !== null) $query->where('tenant_id', $tenantId);

        // P1: ドメイン単位の集計を 1 SQL でまとめ取りして Cache::remember 先回り投入。
        // これをやらないと per-pms ループから 326 ドメイン分の COUNT が直列で走り、
        // 02:40 バッチで statement_timeout → DB::transaction 連鎖崩壊の根因になる。
        // [[project_emails_disk_io_2026_05_25]]
        $tenantsToWarm = $tenantId !== null
            ? [$tenantId]
            : ProjectMailSource::whereNotNull('email_id')
                ->select('tenant_id')->distinct()->pluck('tenant_id')->all();
        foreach ($tenantsToWarm as $tid) {
            $this->prewarmDomainBonusCache((int) $tid);
        }

        $changed = 0;
        // catch/continue ループに DB::transaction wrap は不整合
        // (1 timeout → 1900+ "transaction is aborted" 連鎖) のため per-row autocommit に。
        $applyBatch = function ($rows) use (&$changed) {
            foreach ($rows as $pms) {
                if (!$pms->email) continue;
                $reasons = $pms->score_reasons ?? [];
                // isExcluded 由来の除外（subject/from ベース）は据え置き
                if ($reasons === ['excluded']) continue;
                try {
                    $from = $pms->email->from_address ?? '';

                    // 既存の domain 補正を score_reasons から読み取り、旧行は除去（後で貼り直す）
                    $oldBonus = 0;
                    $reasons  = array_values(array_filter($reasons, function ($r) use (&$oldBonus) {
                        if (is_string($r) && preg_match('/^domain:.+:([+-]?\d+)\(/u', $r, $m)) {
                            $oldBonus = (int) $m[1];
                            return false;
                        }
                        return true;
                    }));

                    $domainData = $this->domainBonus($from, $pms->tenant_id);
                    $newBonus   = $domainData['bonus'];
                    if ($newBonus !== 0) {
                        $sign      = $newBonus > 0 ? '+' : '';
                        $pct       = round($domainData['rate'] * 100);
                        $reasons[] = "domain:{$domainData['domain']}:{$sign}{$newBonus}({$pct}%/{$domainData['sample']}件)";
                    }

                    // base = 現スコア − 旧補正、新スコア = clamp(base + 新補正)
                    $newScore  = max(0, min(100, ((int) $pms->score - $oldBonus) + $newBonus));
                    $newStatus = match(true) {
                        $newScore >= self::SCORE_OK     => 'new',
                        $newScore >= self::SCORE_REVIEW => 'review',
                        default                         => 'excluded',
                    };

                    // 変化なしは書かない（Disk IO 抑制）
                    if ((int) $pms->score === $newScore
                        && $pms->status === $newStatus
                        && ($pms->score_reasons ?? []) === $reasons) {
                        continue;
                    }
                    $pms->update([
                        'score'         => $newScore,
                        'score_reasons' => $reasons,
                        'status'        => $newStatus,
                    ]);
                    $changed++;
                } catch (\Throwable $e) {
                    Log::error("[RefreshDomainBonus] pms_id={$pms->id} 失敗: " . $e->getMessage());
                }
            }
        };

        if ($limit !== null) {
            $applyBatch($query->orderBy('id')->limit($limit)->get());
        } else {
            $query->chunkById($chunkSize, $applyBatch);
        }
        return $changed;
    }

    /**
     * domain_bonus_agg:* キャッシュをテナント単位で 1 SQL でまとめ取りして先回り投入する。
     *
     * domainBonus() の本来のクエリは emails × project_mail_sources の Seq Scan を
     * ドメイン数だけ繰り返すため、cold cache 時 (02:40 nightly refresh) に
     * statement_timeout を踏みやすい。集約 1 本なら全ドメイン 130ms 程度で済む。
     *
     * 既存の `domain_bonus_agg:{tenant}:{domain}` キーと同形の `[total, projectCount]`
     * を Cache::put するので、その後 domainBonus() は Cache::remember でヒットする。
     * skipDomains は domainBonus() が短絡するためキャッシュ不要。
     */
    private function prewarmDomainBonusCache(int $tenantId): void
    {
        try {
            $rows = DB::table('project_mail_sources as pms')
                ->join('emails as e', 'e.id', '=', 'pms.email_id')
                ->where('pms.tenant_id', $tenantId)
                ->where('pms.status', '<>', 'review')
                ->whereNull('pms.deleted_at')
                ->whereNotNull('e.from_address')
                ->groupBy(DB::raw("lower(split_part(e.from_address::text, '@', 2))"))
                ->selectRaw("
                    lower(split_part(e.from_address::text, '@', 2)) AS domain,
                    COUNT(*) AS total,
                    SUM(CASE WHEN pms.status <> 'excluded' THEN 1 ELSE 0 END) AS project_count
                ")
                ->get();

            $warmed = 0;
            foreach ($rows as $r) {
                $domain = (string) $r->domain;
                if ($domain === '' || in_array($domain, self::DOMAIN_BONUS_SKIP, true)) continue;
                Cache::put(
                    "domain_bonus_agg:{$tenantId}:{$domain}",
                    [(int) $r->total, (int) $r->project_count],
                    now()->addHours(6),
                );
                $warmed++;
            }
            Log::info("[RefreshDomainBonus] prewarm tenant={$tenantId} domains={$warmed}");
        } catch (\Throwable $e) {
            // prewarm 失敗は致命的ではない (Cache::remember が fallback で個別集計するだけ)
            Log::warning("[RefreshDomainBonus] prewarm tenant={$tenantId} 失敗: " . $e->getMessage());
        }
    }

    /**
     * 既存レコードの抽出情報だけを再計算（スコアは変えない・バッチ処理対応）
     */
    public function reextractAll(?int $limit = null, int $offset = 0): int
    {
        $query = ProjectMailSource::with('email')
            ->whereNotNull('email_id')
            ->orderBy('id');
        if ($offset > 0) $query->skip($offset);
        if ($limit !== null) $query->limit($limit);

        $count = 0;
        foreach ($query->get() as $pms) {
            if (!$pms->email) continue;
            try {
                $extracted = $this->extract($pms->email);
                $pms->update($this->sanitizeExtracted($extracted));
                $count++;
            } catch (\Throwable $e) {
                Log::error("[ProjectMailExtract] pms_id={$pms->id} 失敗: " . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * 1件スコアリング＋抽出して保存
     */
    public function score(Email $email): ProjectMailSource
    {
        $subject = $email->subject ?? '';
        $body    = $email->body_text ?? strip_tags($email->body_html ?? '');
        $from    = $email->from_address ?? '';
        $text    = $subject . "\n" . $body;

        // ① 除外
        if ($this->isExcluded($subject, $from)) {
            return $this->save($email, 0, ['excluded'], 'rule', []);
        }

        // ①-2 本文 purge ガード: 本文(text/html)とも空なら件名のみの採点は誤検出を生むため
        //     スコアせず excluded アンカーを作る。retention(30日 CleanupEmails)で本文 purge 済みの
        //     メールが catch-up 等で初回スコアされ、件名だけで誤った score/status になるのを防ぐ
        //     （engineer 側の score() と対称。rescoreAll は既存値を温存=skip するが初回は anchor 化）。
        if (trim($body) === '') {
            return $this->save($email, 0, ['excluded', 'body_purged'], 'rule', []);
        }

        // ② スコアリング（max 85点）
        [$score, $reasons] = $this->calcScore($text);

        // ③ ドメイン学習補正（蓄積データが5件以上のドメインに適用）
        $domainData = $this->domainBonus($from, $email->tenant_id);
        if ($domainData['bonus'] !== 0) {
            $score += $domainData['bonus'];
            $sign   = $domainData['bonus'] > 0 ? '+' : '';
            $pct    = round($domainData['rate'] * 100);
            $reasons[] = "domain:{$domainData['domain']}:{$sign}{$domainData['bonus']}({$pct}%/{$domainData['sample']}件)";
        }

        $score     = max(0, min(100, $score));
        $extracted = $this->extract($email);

        return $this->save($email, $score, $reasons, 'rule', $extracted);
    }

    /**
     * ドメイン学習補正値を返す
     * 蓄積データから BP会社ドメインごとの案件率を算出し +20/-20 を返す
     */
    public function domainBonus(string $fromAddress, int $tenantId): array
    {
        $empty = ['bonus' => 0, 'rate' => 0.0, 'sample' => 0, 'domain' => ''];

        if (!$fromAddress) return $empty;

        // ドメイン抽出
        if (!preg_match('/@([\w.\-]+)$/i', $fromAddress, $m)) return $empty;
        $domain = strtolower($m[1]);

        // フォームサービス等は除外（実送信者と無関係なドメイン）
        if (in_array($domain, self::DOMAIN_BONUS_SKIP, true)) return $empty;

        // ランタイムキャッシュ（同一プロセス内の同テナント×同ドメインは再集計しない）
        $cacheKey = "{$tenantId}:{$domain}";
        if (isset($this->domainBonusCache[$cacheKey])) {
            return $this->domainBonusCache[$cacheKey];
        }

        // 判断済みレコードのみ集計（review = 未判断は除外）
        //
        // この集計は emails / project_mail_sources をいずれも Seq Scan するため
        // Disk IO が高い（本番 pg_stat_statements で読み取りIO 第1位・31,615回）。
        // ドメイン案件率は緩やかにしか変わらない統計なので、共有キャッシュ(6h)で
        // 集計回数を「ドメイン数 × 6h に1回」程度まで削減する。
        [$total, $projectCount] = Cache::remember(
            "domain_bonus_agg:{$tenantId}:{$domain}",
            now()->addHours(6),
            function () use ($tenantId, $domain) {
                $rows = ProjectMailSource::where('tenant_id', $tenantId)
                    ->whereNotIn('status', ['review'])
                    ->whereHas('email', fn($q) => $q->where('from_address', 'like', '%@' . $domain))
                    ->selectRaw("
                        COUNT(*) as total,
                        SUM(CASE WHEN status != 'excluded' THEN 1 ELSE 0 END) as project_count
                    ")
                    ->first();

                return [(int) ($rows->total ?? 0), (int) ($rows->project_count ?? 0)];
            }
        );

        // 最低5件のサンプルが必要
        if ($total < 5) {
            return $this->domainBonusCache[$cacheKey] = array_merge($empty, ['domain' => $domain, 'sample' => $total]);
        }

        $rate  = $projectCount / $total;
        $bonus = match(true) {
            $rate >= 0.8 => 20,
            $rate <= 0.2 => -20,
            default      => 0,
        };

        return $this->domainBonusCache[$cacheKey] = [
            'bonus'  => $bonus,
            'rate'   => $rate,
            'sample' => $total,
            'domain' => $domain,
        ];
    }

    // ── 抽出（正規表現）──────────────────────────────────

    public function extract(Email $email): array
    {
        $subject  = $email->subject ?? '';
        // body_text が null だけでなく空文字('')の場合も HTML 本文へフォールバックする。
        // HTML 専用メール（マーケ系の整形メール等）は body_text が '' で取り込まれるため、
        // ?? では HTML に切り替わらず営業担当・電話等を取りこぼしていた。
        $body     = ($email->body_text ?? '') !== ''
            ? $email->body_text
            : $this->htmlToText($email->body_html ?? '');
        $fromName = $email->from_name ?? '';
        $fromAddr = $email->from_address ?? '';

        // 無効なUTF-8バイト列を除去（一部メールに不正バイトが含まれDB insertエラーを防ぐ）
        $subject  = iconv('UTF-8', 'UTF-8//IGNORE', $subject)  ?: '';
        $body     = iconv('UTF-8', 'UTF-8//IGNORE', $body)     ?: '';
        $fromName = iconv('UTF-8', 'UTF-8//IGNORE', $fromName) ?: '';
        $fromAddr = iconv('UTF-8', 'UTF-8//IGNORE', $fromAddr) ?: '';

        // 全角数字を半角へ正規化（'n'=数字のみ）。\d ベースの抽出（単価・年齢・期間・人数等）が
        // 「７５万円」等の全角表記を取りこぼすのを防ぐ。「単金」等の全角スペースは各正規表現側で吸収。
        $subject  = mb_convert_kana($subject, 'n');
        $body     = mb_convert_kana($body, 'n');
        $text     = $subject . "\n" . $body;

        $isSmoothContact = str_contains($fromAddr, 'smoothcontact');

        // from_name を会社名・担当者名に分離する
        [$fromCompany, $fromPerson] = $this->parseFromName($fromName);

        [$priceMin, $priceMax] = $this->extractPriceRange($text);

        return $this->sanitizeUtf8([
            'customer_name'    => $this->extractCustomerName($body, $fromName, $fromAddr, $fromCompany),
            'sales_contact'    => $this->extractSalesContact($body, $isSmoothContact) ?? $fromPerson,
            'phone'            => $this->extractPhone($body, $isSmoothContact),
            'title'            => $this->extractTitle($subject, $body),
            'required_skills'  => $this->extractSkills($text),
            'process'          => $this->extractProcess($text),
            'work_location'    => $this->extractLocation($text),
            'remote_ok'        => $this->extractRemoteOk($text),
            'unit_price_min'   => $priceMin,
            'unit_price_max'   => $priceMax,
            'start_date'       => $this->extractStartDate($text),
            'contract_type'    => $this->extractContractType($text),
            'age_limit'        => $this->extractAgeLimit($text),
            'nationality_ok'   => $this->extractNationalityOk($text),
            'supply_chain'     => $this->extractSupplyChain($text),
        ]);
    }

    /**
     * 抽出結果の文字列値から不正 UTF8 バイト列を除去する。
     * バイト単位の substr（署名抽出等）でマルチバイト境界が切れた値が DB の
     * UTF8 列で "invalid byte sequence" を起こすのを防ぐ（再帰的に配列も処理）。
     */
    private function sanitizeUtf8(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                // mb_scrub は不正・不完全バイトを置換し warning を出さない。
                // iconv //IGNORE は末尾の不完全 multibyte で warning を出し、
                // Laravel がそれを例外化して処理を中断させてしまうため使わない。
                $data[$k] = mb_scrub($v, 'UTF-8');
            } elseif (is_array($v)) {
                $data[$k] = $this->sanitizeUtf8($v);
            }
        }
        return $data;
    }

    /**
     * HTML 本文をプレーンテキスト化する。strip_tags は <style>/<script> の中身（CSS/JS）を
     * 残すため、先にブロックごと除去してから tags を落とし、HTML エンティティも復元する。
     */
    private function htmlToText(string $html): string
    {
        if ($html === '') return '';
        $html = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        // <br>, </p>, </div> 等を改行に（署名ブロック抽出が行単位のため）
        $html = preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $text;
    }

    // ── 各抽出ロジック ─────────────────────────────────────

    private function extractCustomerName(string $body, string $fromName, string $fromAddr, ?string $fromCompany = null): ?string
    {
        // ① SmoothContact フォーム形式: 「[ 御社名 ] Cynet株式会社」
        if (preg_match('/\[[ 　]*御社名[ 　]*\][ 　\t]*([^\n\r\[]{2,80})/u', $body, $m)) {
            return mb_substr(trim($m[1]), 0, 100);
        }

        // ② 本文から「〇〇会社の〇〇と申します」パターン（会社名のみ取得、人名は不要）
        // 例: 「株式会社キャリアビートの渡辺翼空と申します」→「株式会社キャリアビート」
        // ※\p{Hiragana}を除外: "の"が含まれると「〇〇会社の〇〇」まで貪欲マッチしてしまうため
        if (preg_match(
            '/((?:株式|有限|合同|一般社団|一般財団)会社[\p{Han}\p{Katakana}\-・a-zA-Z0-9]+)(?:の[\p{Han}\p{Hiragana}\p{Katakana}ー\w]{1,20})?(?:と申し|でございます|営業部|の者)/u',
            $body, $m
        )) {
            return mb_substr(trim($m[1]), 0, 100);
        }
        // 後置パターン: 「〇〇株式会社の〇〇と申します」
        if (preg_match(
            '/([\p{Han}\p{Katakana}\-・a-zA-Z0-9]+(?:株式|有限|合同)会社)(?:の[\p{Han}\p{Hiragana}\p{Katakana}ー\w]{1,20})?(?:と申し|でございます|営業部|の者)/u',
            $body, $m
        )) {
            return mb_substr(trim($m[1]), 0, 100);
        }

        // ②-b: 署名ブロック内の独立した会社名行（セパレータ***の後）
        // 例: "*-*-*-*\n株式会社ルートゼロ\n高原斗亜" → "株式会社ルートゼロ"
        // ルートゼロ高原のように本文に"株式会社"が現れず署名のみにある場合をカバー
        if (preg_match('/[\*＊\-]{4,}[\r\n]+([\s\S]{0,600})/u', $body, $sigBlock)) {
            if (preg_match(
                '/^\s*((?:株式|有限|合同|一般社団|一般財団)会社[\p{Han}\p{Katakana}\-・a-zA-Z0-9]{1,30})\s*[\r\n]/mu',
                $sigBlock[1], $m2
            )) {
                return mb_substr(trim($m2[1]), 0, 100);
            }
        }

        // ③ 明示ラベル（クライアント：, エンド：, 常駐先：等）
        if (preg_match(
            '/(?:クライアント|エンド(?:先|クライアント)?|常駐先|顧客|発注元|取引先|企業名)\s*[：:]\s*([^\n\r　]{2,50})/u',
            $body, $m
        )) {
            return mb_substr(trim($m[1]), 0, 100);
        }

        // ④ from_name を parseFromName で分離した会社名を使う（人名は sales_contact へ）
        if ($fromCompany !== null) {
            return mb_substr($fromCompany, 0, 100);
        }

        // ⑤ parseFromName が判断できなかった場合のフォールバック
        if ($fromName) {
            $name = trim($fromName);
            if (mb_strlen($name) >= 4 && !preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}ー\s]{2,6}$/u', $name)) {
                return mb_substr($name, 0, 100);
            }
        }

        // ⑥ ドメインから推測
        if ($fromAddr && preg_match('/@([\w\-]+)\.(?:co\.jp|com|jp)/i', $fromAddr, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * from_name を [会社名, 担当者名] に分離する
     * 例: "株式会社テック 田中太郎" → ["株式会社テック", "田中太郎"]
     * 例: "テック株式会社" → ["テック株式会社", null]
     * 例: "田中太郎" → [null, "田中太郎"]
     */
    private function parseFromName(string $fromName): array
    {
        if (!$fromName) return [null, null];
        $name = trim($fromName);

        // 前置会社名 + スペース + 候補
        if (preg_match('/^((?:株式|有限|合同|一般社団|一般財団)会社[\p{Han}\p{Hiragana}\p{Katakana}\-・a-zA-Z0-9]*)[\s　]+(.{2,20})$/u', $name, $m)) {
            $company = trim($m[1]);
            $person  = trim($m[2]);
            return [$company, $this->looksLikePersonName($person) ? $person : null];
        }

        // 後置会社名 + スペース + 候補
        if (preg_match('/^([\p{Han}\p{Hiragana}\p{Katakana}\-・a-zA-Z0-9]+(?:株式|有限|合同)会社)[\s　]+(.{2,20})$/u', $name, $m)) {
            $company = trim($m[1]);
            $person  = trim($m[2]);
            return [$company, $this->looksLikePersonName($person) ? $person : null];
        }

        // 前置会社名のみ
        if (preg_match('/^(?:株式|有限|合同|一般社団|一般財団)会社/u', $name)) {
            return [mb_substr($name, 0, 100), null];
        }

        // 後置会社名のみ
        if (preg_match('/(?:株式|有限|合同)会社$/u', $name)) {
            return [mb_substr($name, 0, 100), null];
        }

        // カタカナ会社名 + 漢字人名（スペースなし）
        // 例: "ルートゼロ高原" → ["ルートゼロ", "高原"]
        if (preg_match('/^([\p{Katakana}\-・ａ-ｚＡ-Ｚa-zA-Z0-9]{3,})([\p{Han}]{2,4})$/u', $name, $m)) {
            $company = trim($m[1]);
            $person  = trim($m[2]);
            if ($this->looksLikePersonName($person)) {
                return [$company, $person];
            }
        }

        // 前置会社名 + 漢字人名（スペースなし）
        // 例: "株式会社テック田中" → ["株式会社テック", "田中"]
        if (preg_match('/^((?:株式|有限|合同|一般社団|一般財団)会社[\p{Han}\p{Hiragana}\p{Katakana}\-・a-zA-Z0-9]+?)([\p{Han}]{2,4})$/u', $name, $m)) {
            $company = trim($m[1]);
            $person  = trim($m[2]);
            if ($this->looksLikePersonName($person) && mb_strlen($company) >= 4) {
                return [$company, $person];
            }
        }

        // 人名のみ
        if ($this->looksLikePersonName($name)) {
            return [null, $name];
        }

        return [null, null];
    }

    private function looksLikePersonName(string $str): bool
    {
        $str = trim($str);
        // 2〜15文字の日本語名（漢字・かな・スペース区切りも許容）
        return (bool) preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}ー\s]{2,15}$/u', $str)
            && mb_strlen(str_replace(' ', '', $str)) >= 2
            && mb_strlen($str) <= 15;
    }

    private function extractSalesContact(string $body, bool $isSmoothContact): ?string
    {
        if ($isSmoothContact) {
            // SmoothContact: 「[ ご担当者様 ] 山路 康太郎 (ヤマジ コウタロウ)」
            if (preg_match('/\[[ 　]*ご担当者様[ 　]*\][ 　\t]*([^\n\r\[]{2,80})/u', $body, $m)) {
                // 読み仮名（括弧内）を除去: 「山路 康太郎 (ヤマジ コウタロウ)」→「山路 康太郎」
                $name = preg_replace('/\s*[（(][^）)]*[）)]\s*$/', '', trim($m[1]));
                return mb_substr(trim($name), 0, 100);
            }
            return null;
        }

        // 一般メール: 担当者ラベル
        if (preg_match('/(?:担当者?|ご担当|連絡先担当|営業担当)\s*[：:]\s*([^\n\r　]{2,50})/u', $body, $m)) {
            return mb_substr(trim($m[1]), 0, 100);
        }

        // 署名ブロックから人名を抽出
        // パターン: [***区切り] → 会社名行 → [部署行(省略可)] → 人名行(ふりがな省略可)
        // 例: "*-*-*\n株式会社ルートゼロ\n高原斗亜(たかはらとあ)" → "高原斗亜"
        // 例: "*-*-*\n株式会社ルートゼロ\n営業企画部...\n榎本 勝也" → "榎本 勝也"
        if (preg_match('/[\*＊\-]{4,}[\r\n]+/u', $body, $sepMatch, PREG_OFFSET_CAPTURE)) {
            $sigStart = $sepMatch[0][1] + strlen($sepMatch[0][0]);
            $sigText  = substr($body, $sigStart, 800); // バイトオフセット → substr を使う
            $sigLines = preg_split('/\r?\n/', $sigText);
            $foundCompany = false;
            $deptSkipped  = false;
            foreach ($sigLines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (!$foundCompany && preg_match('/(?:株式|有限|合同|一般社団|一般財団)会社/u', $line)) {
                    $foundCompany = true;
                    continue;
                }
                if ($foundCompany) {
                    // 部署・役職行はスキップ（最大1行）
                    if (!$deptSkipped && preg_match('/(?:部|課|リーダー|マネージャー|事業|部門|支社|本社)/u', $line)) {
                        $deptSkipped = true;
                        continue;
                    }
                    // ふりがな（括弧内）を除去して人名チェック
                    $clean = preg_replace('/\([^)]{2,20}\)|（[^）]{2,20}）/', '', $line);
                    $clean = trim($clean);
                    if ($this->looksLikePersonName($clean)) {
                        return $clean;
                    }
                    break;
                }
            }
        }

        // フォールバック: 本文冒頭の自己紹介パターン
        // 例: "インテグレートの小玉でございます" / "テックの山田です"
        $headBody = mb_substr($body, 0, 500);
        if (preg_match('/[\p{Han}\p{Katakana}a-zA-Z]{2,}の([\p{Han}]{2,4})(?:でございます|です|と申します)/u', $headBody, $m)) {
            $candidate = trim($m[1]);
            if ($this->looksLikePersonName($candidate) && !preg_match('/部|課|設計|構築|開発|営業|紹介|担当|管理/u', $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractPhone(string $body, bool $isSmoothContact): ?string
    {
        if ($isSmoothContact) {
            // SmoothContact: 「[ お電話番号 ] 090-1234-5678」
            if (preg_match('/\[[ 　]*お電話番号[ 　]*\][ 　\t]*([^\n\r\[]{4,30})/u', $body, $m)) {
                return mb_substr(trim($m[1]), 0, 50);
            }
            return null;
        }

        // 一般メール: 電話番号ラベル or 裸の電話番号
        if (preg_match('/(?:電話番号?|TEL|Tel)\s*[：:。\s]*([0-9０-９(（)）\-－]{8,20})/u', $body, $m)) {
            return mb_substr(trim($m[1]), 0, 50);
        }
        if (preg_match('/0[0-9]{1,3}[-－][0-9]{2,4}[-－][0-9]{3,4}/', $body, $m)) {
            return $m[0];
        }
        return null;
    }

    private function extractTitle(string $subject, string $body): ?string
    {
        // 件名から【】を除去してタイトルとして使う
        $title = preg_replace('/【[^】]*】/', '', $subject);
        $title = trim($title);
        if (mb_strlen($title) >= 5) {
            return mb_substr($title, 0, 200);
        }
        // 件名が短い場合は本文1行目を使う
        $lines = array_filter(explode("\n", $title . "\n" . $body));
        foreach ($lines as $line) {
            $line = trim($line);
            if (mb_strlen($line) >= 10 && mb_strlen($line) <= 100) {
                return $line;
            }
        }
        return $subject ?: null;
    }

    private function extractSkills(string $text): array
    {
        // URL内の文字列をスキル名と誤検出しないよう除去（例: cc.php → PHP と誤認）
        $textWithoutUrls = preg_replace(self::URL_PATTERN, '', $text) ?? $text;

        $found = [];
        $allSkills = array_merge(self::TECH_LANG, self::TECH_INFRA, self::TECH_DB);
        foreach ($allSkills as $skill) {
            if ($this->skillFound($textWithoutUrls, $skill)) {
                $found[] = $skill;
            }
        }
        return array_values(array_unique($found));
    }

    /**
     * スキルキーワードが「単語として」テキスト内に存在するか判定
     * 前後が英数字・スラッシュ・ドットの場合は除外（パス断片・英単語の一部を誤検出しない）
     * 例: "go" in ".go.jp" → false / "Go" in "Go言語" → true
     */
    private function skillFound(string $text, string $skill): bool
    {
        $escaped = preg_quote($skill, '/');
        return (bool) preg_match('/(?<![a-zA-Z0-9\/\.])' . $escaped . '(?![a-zA-Z0-9\/\.])/iu', $text);
    }

    private function extractProcess(string $text): array
    {
        $found = [];
        $allProcess = array_merge(self::PROCESS_UPPER, self::PROCESS_DEV, self::PROCESS_OTHER);
        foreach ($allProcess as $p) {
            if (mb_strpos($text, $p) !== false) {
                $found[] = $p;
            }
        }
        return array_values(array_unique($found));
    }

    private function extractLocation(string $text): ?string
    {
        // 「【場所】〇〇」「勤務地：〇〇」「場所：〇〇」「最寄駅：〇〇」
        if (preg_match('/【(?:勤務地|就業場所|作業場所|場所)】\s*([^\n\r　]{2,30})/u', $text, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/(?:勤務地|就業場所|作業場所|場所)\s*[：:]\s*([^\n\r　]{2,30})/u', $text, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/(?:最寄[駅り]?)\s*[：:]\s*([^\n\r　]{2,20})/u', $text, $m)) {
            return trim($m[1]);
        }
        // 都道府県パターン
        if (preg_match('/([東西南北]?(?:東京|大阪|名古屋|横浜|福岡|仙台|札幌|神奈川|埼玉|千葉)[^\n\r　]{0,20})/u', $text, $m)) {
            $loc = trim($m[1]);
            // 役職・部署名が混入した場合は除去（例: 「東京支社 リーダー」→「東京支社」）
            $loc = preg_replace('/[\s　]+(?:リーダー|マネージャー|支社長|部長|課長|社長|主任|係長|代表|取締役|担当者?|グループ長|チーフ|ディレクター).*/u', '', $loc);
            return $loc ?: null;
        }
        return null;
    }

    private function extractRemoteOk(string $text): ?bool
    {
        // 不可を先に判定
        if (preg_match('/(?:リモート不可|フル出社|常駐必須|出社必須|在宅不可)/u', $text)) {
            return false;
        }
        if (preg_match('/(?:フルリモート|完全リモート|リモートOK|リモート可|テレワーク可|在宅(?:勤務)?可|週[2-5]リモート|一部リモート)/u', $text)) {
            return true;
        }
        // 「常駐」単独は不可寄り
        if (mb_strpos($text, '常駐') !== false && mb_strpos($text, 'リモート') === false) {
            return false;
        }
        return null;
    }

    /**
     * 単価レンジ [min, max] を抽出する。
     *
     * ① 単価/単金/月額 ラベル近傍を最優先する。これにより「■人数■ 2~3名」等の別ラベルの
     *    数字や、「5,500万ID」「約400万契約」のような非価格の数字を拾わない。
     * ② ラベルが無い場合のみ本文全体から控えめにフォールバック（カンマ区切りの大きな数字
     *    "5,500万" は (?<![\d,]) で除外）。
     * 区切りは 〜 ～ ~ と - － の両方に対応（「80 - 90万円」型）。
     * 入力 $text は呼び出し側で mb_convert_kana('n') 済（全角数字は半角化されている前提）。
     */
    private function extractPriceRange(string $text): array
    {
        // ① 単価ラベル近傍（ラベル直後 ~30 文字・改行跨ぎ可）。「単　価」の全角スペースも吸収。
        if (preg_match('/(?:単[　\s]*[価金]|月額|月単価|希望単価|想定単価)[】■\]\s:：]*([\s\S]{0,30})/u', $text, $lm)) {
            $w = $lm[1];
            // レンジ「80 - 90万」「80〜90万」「80万〜90万」
            if (preg_match('/(\d{2,3})\s*(?:万円?)?\s*[〜～~\-－]\s*(\d{2,3})\s*万/u', $w, $m)) {
                return [(float) min($m[1], $m[2]), (float) max($m[1], $m[2])];
            }
            // 上限のみ「〜120万」
            if (preg_match('/[〜～~\-－]\s*(\d{2,3})\s*万/u', $w, $m)) {
                return [null, (float) $m[1]];
            }
            // 単独「85万」
            if (preg_match('/(\d{2,3})\s*万/u', $w, $m)) {
                return [(float) $m[1], (float) $m[1]];
            }
        }

        // ② フォールバック（ラベル無し）。カンマ区切りの大きな数字(5,500万)は除外。
        // 先頭側の万は任意（「60〜80万」「60万〜80万」両対応）。
        if (preg_match('/(?<![\d,])(\d{2,3})\s*(?:万円?)?\s*[〜～~\-－]\s*(\d{2,3})\s*万/u', $text, $m)) {
            return [(float) min($m[1], $m[2]), (float) max($m[1], $m[2])];
        }
        if (preg_match('/[〜～~]\s*(?<![\d,])(\d{2,3})\s*万/u', $text, $m)) {
            return [null, (float) $m[1]];
        }
        if (preg_match('/(?<![\d,])(\d{2,3})\s*万[円]?/u', $text, $m)) {
            return [(float) $m[1], (float) $m[1]];
        }
        return [null, null];
    }

    private function extractStartDate(string $text): ?string
    {
        // URLを除去してから判定（トラッキングURLの数字列を誤検出しない）
        $text = preg_replace(self::URL_PATTERN, '', $text) ?? $text;

        if (preg_match('/(?:即日|即時|即スタート)/u', $text)) {
            return '即日';
        }
        // 「2026年5月」「2026/05」「5月〜」「6月上旬」
        if (preg_match('/(\d{4})\s*年\s*(\d{1,2})\s*月/u', $text, $m)) {
            return "{$m[1]}-{$m[2]}";
        }
        // 年として妥当な範囲（2020〜2035）のみ許容
        if (preg_match('/(\d{4})[\/\-](\d{1,2})/u', $text, $m) && (int)$m[1] >= 2020 && (int)$m[1] <= 2035) {
            return "{$m[1]}-{$m[2]}";
        }
        if (preg_match('/(\d{1,2})\s*月(?:[上中下]旬|初め|末)?(?:[〜～~]|から|より)/u', $text, $m)) {
            return (int)$m[1] . '月〜';
        }
        return null;
    }

    private function extractContractType(string $text): ?string
    {
        if (mb_strpos($text, '準委任') !== false) return '準委任';
        if (mb_strpos($text, '派遣') !== false)   return '派遣';
        if (mb_strpos($text, '請負') !== false)   return '請負';
        return null;
    }

    private function extractAgeLimit(string $text): ?string
    {
        if (preg_match('/(?:年齢[：:\s]*)?[〜～~上]?\s*(\d{2,3})\s*歳(?:まで|以下|未満)/u', $text, $m)) {
            return '〜' . $m[1] . '歳';
        }
        if (preg_match('/(\d{2,3})\s*歳[〜～~]\s*(\d{2,3})\s*歳/u', $text, $m)) {
            return $m[1] . '〜' . $m[2] . '歳';
        }
        return null;
    }

    private function extractNationalityOk(string $text): ?bool
    {
        if (preg_match('/(?:外国籍不可|日本人のみ|日本国籍|日本語ネイティブのみ)/u', $text)) {
            return false;
        }
        if (preg_match('/(?:外国籍可|国籍不問|外国籍OK)/u', $text)) {
            return true;
        }
        return null;
    }

    private function extractSupplyChain(string $text): ?int
    {
        if (preg_match('/(?:元請|エンド直|一次請?け?)/u', $text)) return 1;
        if (preg_match('/二次請?け?/u', $text)) return 2;
        if (preg_match('/三次請?け?/u', $text)) return 3;
        return null;
    }

    // ── スコア計算 ────────────────────────────────────────

    private function isExcluded(string $subject, string $from): bool
    {
        foreach (self::EXCLUDE_SUBJECT as $kw) {
            if (mb_strpos($subject, $kw) !== false) return true;
        }
        foreach (self::EXCLUDE_FROM as $kw) {
            if (str_contains(strtolower($from), $kw)) return true;
        }
        // 自社ドメインは除外（自社営業担当からのメール）
        foreach (self::EXCLUDE_DOMAIN as $domain) {
            if (str_ends_with(strtolower($from), '@' . $domain)) return true;
        }
        return false;
    }

    private function calcScore(string $text): array
    {
        $score = 0; $reasons = [];
        $textWithoutUrls = preg_replace(self::URL_PATTERN, '', $text) ?? $text;

        // [A] 案件確度A: 明示的案件紹介ワード (+15, max 1回)
        foreach (self::PROJECT_A as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score += 15; $reasons[] = "project_a:{$kw}"; break;
            }
        }

        // [B] 案件確度B: 条件提示ワード (+10, max 1回)
        foreach (self::PROJECT_B as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score += 10; $reasons[] = "project_b:{$kw}"; break;
            }
        }

        // [C] 技術スタック (max 20)
        $techScore = 0; $langCount = 0;
        foreach (self::TECH_LANG as $kw) {
            if ($this->skillFound($textWithoutUrls, $kw)) {
                $langCount++;
                if ($langCount === 1) { $techScore += 10; $reasons[] = "lang:{$kw}"; }
                elseif ($langCount === 2) { $techScore += 5;  $reasons[] = "lang2:{$kw}"; break; }
            }
        }
        foreach (self::TECH_INFRA as $kw) {
            if ($this->skillFound($textWithoutUrls, $kw)) { $techScore += 5; $reasons[] = "infra:{$kw}"; break; }
        }
        foreach (self::TECH_DB as $kw) {
            if ($this->skillFound($textWithoutUrls, $kw)) { $techScore += 3; $reasons[] = "db:{$kw}"; break; }
        }
        $score += min($techScore, 20);

        // [D] 単価具体性: XX万 という数字 (+15)
        if (preg_match('/\d{2,3}\s*万[円]?/u', $text)) {
            $score += 15; $reasons[] = 'price_concrete';
        }

        // [E] 勤務地 (+10, max 1回)
        foreach (self::LOCATION_KW as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score += 10; $reasons[] = "location:{$kw}"; break;
            }
        }

        // [F] 工程 (max 10)
        $procAdded = false;
        foreach (self::PROCESS_UPPER as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score += 10; $reasons[] = "process:{$kw}"; $procAdded = true; break;
            }
        }
        if (!$procAdded) {
            foreach (self::PROCESS_DEV as $kw) {
                if (mb_strpos($text, $kw) !== false) {
                    $score += 7; $reasons[] = "process:{$kw}"; $procAdded = true; break;
                }
            }
        }
        if (!$procAdded) {
            foreach (self::PROCESS_OTHER as $kw) {
                if (mb_strpos($text, $kw) !== false) {
                    $score += 4; $reasons[] = "process:{$kw}"; break;
                }
            }
        }

        // [G] 稼働・期間 (+5)
        foreach (['即日', '長期', '人月'] as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score += 5; $reasons[] = "timing:{$kw}"; break;
            }
        }

        // ペナルティ: 単価曖昧 (-10)
        foreach (self::PENALTY_VAGUE as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score -= 10; $reasons[] = "penalty_vague:{$kw}"; break;
            }
        }

        // ペナルティ: 高次商流 (-10)
        foreach (self::PENALTY_CHAIN as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score -= 10; $reasons[] = "penalty_chain:{$kw}"; break;
            }
        }

        return [max(0, min(85, $score)), $reasons];
    }

    // ── 保存 ──────────────────────────────────────────────

    private function save(Email $email, int $score, array $reasons, string $engine, array $extracted): ProjectMailSource
    {
        $status = match(true) {
            $score === 0              => 'excluded',
            $score >= self::SCORE_OK  => 'new',
            $score >= self::SCORE_REVIEW => 'review',
            default                   => 'excluded',
        };

        return ProjectMailSource::updateOrCreate(
            ['email_id' => $email->id],
            array_merge([
                'tenant_id'    => $email->tenant_id,
                'score'        => $score,
                'score_reasons'=> $reasons,
                'engine'       => $engine,
                'status'       => $status,
                'received_at'  => $email->received_at,
                'arrived_at'   => $email->arrived_at,
            ], $this->sanitizeExtracted($extracted))
        );
    }

    private function sanitizeExtracted(array $extracted): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
            return $value;
        }, $extracted);
    }
}
