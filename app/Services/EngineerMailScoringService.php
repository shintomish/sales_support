<?php

namespace App\Services;

use App\Models\Email;
use App\Models\EngineerMailSource;
use App\Models\GmailToken;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Illuminate\Support\Facades\Log;
use App\Services\SkillSheetTextExtractor;
use App\Services\SupabaseStorageService;

/**
 * 技術者メール判定・スコアリング＋正規表現抽出サービス
 *
 * ① 除外判定 → ② スコア判定 → ③ 情報抽出
 */
class EngineerMailScoringService
{
    // ── ① 除外ワード ──────────────────────────────────────

    private const EXCLUDE_SUBJECT = [
        '配信停止', 'メルマガ', '広告', '請求書', 'お支払い',
        'ご挨拶', 'お知らせ',
    ];

    private const EXCLUDE_FROM = ['no-reply', 'noreply'];

    // 自社ドメイン + 営業判断で受信不要とした取引先ドメイン (2026-06-22 新冨さん指示)
    private const EXCLUDE_DOMAIN = ['aizen-sol.co.jp', 'b-tm.co.jp', 'careerbeat.jp'];

    // ── ② スコア辞書（max 85点設計）────────────────────────

    // [A] 明示的技術者紹介ワード (+15)
    private const ENGINEER_A = [
        'スキルシート', '経歴書', '職務経歴書',
        '要員ご紹介', '技術者ご紹介', '人材ご紹介', 'エンジニアご紹介',
        '技術者情報', '要員情報', 'エンジニア情報',
    ];

    // [B] 稼働条件ワード (+10)
    private const ENGINEER_B = [
        '稼働開始', '稼働可能', '空きあり', '対応可能',
        '稼働率', '即稼働', '即日対応', '参画可能',
    ];

    // [C] 技術スタック (+3/件 max 20)
    private const TECH_STACK = [
        // 言語（モダン）
        'Java', 'PHP', 'Python', 'C#', 'Ruby', 'Go', 'Golang',
        'Swift', 'Kotlin', 'TypeScript', 'JavaScript', 'Scala', 'Rust',
        'Dart', 'Perl', 'C++',
        // 言語（レガシー）
        'COBOL', 'VBA', 'VB.NET', 'PL/SQL', 'PL/I', 'JCL', 'RPG',
        // FW・ライブラリ
        'Spring', 'SpringBoot', 'Laravel', 'Django', 'Flask', 'FastAPI',
        'Rails', 'React', 'Vue', 'Angular', 'Next.js', 'Nuxt.js',
        'NestJS', 'Express', 'Struts', 'MyBatis', 'Hibernate', 'Gin', '.NET',
        // モバイル
        'Flutter', 'React Native', 'Swift', 'Kotlin', 'Xcode',
        // クラウド（AWS）
        'AWS', 'EC2', 'RDS', 'S3', 'Lambda', 'ECS', 'EKS', 'Fargate',
        'CloudFront', 'DynamoDB', 'SQS', 'SNS', 'CloudFormation',
        // クラウド（その他）
        'Azure', 'GCP',
        // インフラ・DevOps
        'Docker', 'Kubernetes', 'Linux', 'Terraform', 'Ansible', 'Jenkins',
        'GitHub Actions', 'GitLab CI', 'Nginx', 'Apache', 'Prometheus', 'Grafana',
        // DB
        'MySQL', 'PostgreSQL', 'Oracle', 'SQLServer', 'MongoDB', 'Redis',
        'Elasticsearch', 'Firebase', 'BigQuery', 'DynamoDB', 'SQLite',
        // ツール
        'GitHub', 'GitLab', 'Bitbucket', 'Jira', 'Confluence',
        // メインフレーム
        'z/OS', 'CICS', 'AS/400',
    ];

    // [D] 所属区分ワード (+10)
    private const AFFILIATION_KW = [
        'BP', 'フリーランス', '自社社員', '下請け', '一社先', '個人事業主', '契約社員',
    ];

    private const SCORE_OK       = 60;
    private const SCORE_REVIEW   = 40;
    private const PRICE_MIN_FLOOR = 35; // 万円：これ未満は除外

    // ── 所属区分マッピング ─────────────────────────────────

    private const AFFILIATION_MAP = [
        '自社正社員'   => ['自社正社員', '自社社員', 'プロパー'],
        '一社先正社員' => ['1社先', '一社先', '一社下', '1次', '一次下請け', '一次請け'],
        'BP'           => ['BP', 'ビジネスパートナー'],
        'BP要員'       => ['BP要員'],
        '契約社員'     => ['契約社員'],
        '個人事業主'   => ['個人事業主', 'フリーランス', '独立'],
        '入社予定'     => ['入社予定'],
        '採用予定'     => ['採用予定'],
    ];

    // ── 公開メソッド ──────────────────────────────────────

    /**
     * 未処理メールの件数を返す
     *
     * received_at の窓は scorePending() と同じ既定（1日）を使用。
     * 過去全件カウントしたい場合は $lookbackDays = null を渡す。
     */
    public function pendingCount(?int $lookbackDays = 1): int
    {
        $query = Email::where('category', 'engineer')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                  ->from('engineer_mail_sources')
                  ->whereColumn('engineer_mail_sources.email_id', 'emails.id');
            });

        if ($lookbackDays !== null) {
            $query->where('received_at', '>=', now()->subDays($lookbackDays));
        }

        return $query->count();
    }

    /**
     * 未処理の技術者メールを一括スコアリング
     *
     * @param int|null $limit          処理件数の上限
     * @param bool     $withAttachment 添付ファイルをClaudeで解析するか（手動取込時はfalseで高速化）
     * @param int|null $lookbackDays   何日前までのメールを対象とするか（既定1日／null=全期間）
     *
     * 既定で1日の窓を設けることで、毎15分のスケジューラ実行時に
     * 全期間（数万件）を NOT EXISTS で再スキャンするのを防止する。
     * 数日分の取りこぼしを再処理したい場合は手動で $lookbackDays を指定する。
     */
    public function scorePending(?int $limit = null, bool $withAttachment = false, ?int $lookbackDays = 1): int
    {
        $skipFlagDir = storage_path('app/score_skip');
        if (!is_dir($skipFlagDir)) mkdir($skipFlagDir, 0755, true);

        // クラッシュ済みメール（フラグファイルが残っている）をスキップ対象に追加
        $skipIds = array_map(
            fn($f) => (int) pathinfo($f, PATHINFO_FILENAME),
            glob("{$skipFlagDir}/*.skip") ?: []
        );

        $query = Email::where('category', 'engineer')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                  ->from('engineer_mail_sources')
                  ->whereColumn('engineer_mail_sources.email_id', 'emails.id');
            })
            ->when($lookbackDays !== null, fn($q) => $q->where('received_at', '>=', now()->subDays($lookbackDays)))
            ->when(!empty($skipIds), fn($q) => $q->whereNotIn('id', $skipIds))
            ->with('attachments')
            ->orderByDesc('received_at');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $count = 0;
        foreach ($query->get() as $email) {
            $flagFile = "{$skipFlagDir}/{$email->id}.skip";
            // クラッシュ検出用フラグ作成（完了時に削除）
            file_put_contents($flagFile, date('Y-m-d H:i:s'));
            Log::info("[EngineerMailScoring] 処理開始 email_id={$email->id}");
            try {
                $this->score($email, $withAttachment);
                $count++;
                Log::info("[EngineerMailScoring] 処理完了 email_id={$email->id}");
                @unlink($flagFile);
            } catch (\Throwable $e) {
                Log::error("[EngineerMailScoring] email_id={$email->id} 失敗: " . $e->getMessage());
                @unlink($flagFile);
            }
        }

        return $count;
    }

    /**
     * 既存レコードを全件再スコアリング＋再抽出
     *
     * @param int|null $limit  処理件数の上限
     * @param int      $offset 処理開始位置（バッチ処理用）
     */
    /**
     * Shadow rescore — UPDATE せずに score/status 変動だけ集計する pre-deploy 検証。
     *
     * 用途 (docs/730 #1 / 5/25 営業部決定の本番投入前):
     *   - 「スコア0点化/年齢許容/表示閾値70+」変更後の status 遷移件数を事前可視化
     *   - 35万/月未満除外などの単価ロジック変更影響範囲を定量化
     *
     * project と異なり engineer は単価チェックが status に効くため extract() を呼ぶ
     * (添付解析はスキップ)。それでも UPDATE しないため Disk IO は限定。
     *
     * @return array{
     *   total:int, unchanged:int, changed_score:int, changed_status:int,
     *   crossed_review_threshold:int, crossed_ok_threshold:int,
     *   transitions: array<string,int>,
     *   sample_changes: array<int, array{ems_id:int, old_score:int, new_score:int, old_status:?string, new_status:string}>
     * }
     */
    public function rescoreAllShadow(?int $limit = null, int $offset = 0, ?int $tenantId = null): array
    {
        ini_set('memory_limit', '512M');

        $query = EngineerMailSource::with(['email.attachments'])
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

        foreach ($query->cursor() as $ems) {
            if (!$ems->email) continue;
            try {
                $email   = $ems->email;
                $subject = $email->subject ?? '';
                $from    = $email->from_address ?? '';

                $oldScore  = (int) $ems->score;
                $oldStatus = $ems->status;

                $body = $email->body_text ?? strip_tags($email->body_html ?? '');
                if ($this->isExcluded($subject, $from)) {
                    $newScore  = 0;
                    $newStatus = 'excluded';
                } elseif (trim($body) === '') {
                    // 本文が retention(30日 CleanupEmails) で purge 済み。件名のみの再スコアは
                    // 破壊的なので保存値を温存する（差分なし=unchanged 扱い）。
                    $newScore  = $oldScore;
                    $newStatus = $oldStatus;
                } else {
                    $text = $subject . "\n" . $body;

                    [$newScore, $reasons] = $this->calcScore($text, $email);
                    $newScore = max(0, min(100, $newScore));
                    $extracted = $this->extract($email, false);
                    // [A] 本文に単価が無ければ抽出済みスキルシート本文から救済
                    [$priceMin, $priceMax] = $this->resolveUnitPriceRange($extracted, $ems->parsed_skill_sheet_text);
                    $newStatus = $this->priceAdjustedStatus($newScore, $reasons, $priceMin ?? $priceMax);
                }

                $stats['total']++;
                $scoreChanged  = $oldScore !== $newScore;
                $statusChanged = $oldStatus !== $newStatus;

                if (!$scoreChanged && !$statusChanged) {
                    $stats['unchanged']++;
                    gc_collect_cycles();
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
                        'ems_id'     => $ems->id,
                        'old_score'  => $oldScore,
                        'new_score'  => $newScore,
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus,
                    ];
                }
            } catch (\Throwable $e) {
                Log::error("[EngineerMailRescoreShadow] ems_id={$ems->id} 失敗: " . $e->getMessage());
            }
            gc_collect_cycles();
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
        ini_set('memory_limit', '512M');

        // 大量処理のため chunk で分割して処理（メモリ節約）
        $query = EngineerMailSource::with(['email.attachments'])
            ->whereNotNull('email_id')
            ->orderBy('id');
        if ($tenantId !== null) $query->where('tenant_id', $tenantId);
        if ($offset > 0) $query->skip($offset);
        if ($limit !== null) $query->limit($limit);

        $count = 0;
        foreach ($query->cursor() as $ems) {
            if (!$ems->email) continue;
            try {
                $email   = $ems->email;
                $subject = $email->subject ?? '';
                $from    = $email->from_address ?? '';

                $body = $email->body_text ?? strip_tags($email->body_html ?? '');
                if ($this->isExcluded($subject, $from)) {
                    $ems->update(['score' => 0, 'score_reasons' => ['excluded'], 'status' => 'excluded']);
                } elseif (trim($body) === '') {
                    // 本文が retention(30日 CleanupEmails) で purge 済み。件名のみの再スコアは
                    // 破壊的なので保存値を温存する（update せずスキップ）。
                } else {
                    $text = $subject . "\n" . $body;

                    [$score, $reasons, $bd] = $this->calcScore($text, $email);
                    $score     = max(0, min(100, $score));
                    // rescoreAll では添付解析をスキップ（タイムアウト防止）
                    $extracted = $this->extract($email, false);
                    // save() と同じ単価チェック (docs/730 §High #2)。
                    // [A] 本文に単価が無ければ抽出済みスキルシート本文から救済し、
                    //     [B] それでも無ければ除外せず review 止まりにする (priceAdjustedStatus 内)。
                    [$priceMin, $priceMax]       = $this->resolveUnitPriceRange($extracted, $ems->parsed_skill_sheet_text);
                    $extracted['unit_price_min'] = $priceMin;
                    $extracted['unit_price_max'] = $priceMax;
                    $status = $this->priceAdjustedStatus($score, $reasons, $priceMin ?? $priceMax);
                    $ems->update(array_merge($extracted, [
                        'score'          => $score,
                        'score_reasons'  => $reasons,
                        'score_breakdown'=> $bd,
                        'engine'         => 'rule',
                        'status'         => $status,
                    ]));
                }
                $count++;
            } catch (\Throwable $e) {
                Log::error("[EngineerMailRescore] ems_id={$ems->id} 失敗: " . $e->getMessage());
            }
            // メモリ解放
            gc_collect_cycles();
        }
        return $count;
    }

    /**
     * 営業向けスコア内訳のみを再計算して返す（保存・status/score 変更なし）。既存行のバックフィル用。
     * [E] 添付加点のため attachments をロードする。本文が purge 済みなら空配列。
     */
    public function calcScoreBreakdown(Email $email): array
    {
        $body = $email->body_text ?? strip_tags($email->body_html ?? '');
        if (trim($body) === '') return [];
        $email->loadMissing('attachments');
        [, , $bd] = $this->calcScore(($email->subject ?? '') . "\n" . $body, $email);
        return $bd;
    }

    /**
     * 1件スコアリング＋抽出して保存
     *
     * @param bool $withAttachment 添付ファイルをClaudeで解析するか
     */
    public function score(Email $email, bool $withAttachment = false): EngineerMailSource
    {
        $subject = $email->subject ?? '';
        $from    = $email->from_address ?? '';

        // ① 除外
        if ($this->isExcluded($subject, $from)) {
            return $this->save($email, 0, ['excluded'], 'rule', []);
        }

        // ①-2 本文 purge ガード: 本文(text/html)とも空なら件名のみの採点は誤検出を生むため
        //     スコアせず excluded アンカーを作る。retention(30日 CleanupEmails)で本文 purge 済みの
        //     メールが catch-up 等で初回スコアされると、本来高スコアの技術者が件名だけで誤った
        //     score/status になり提案対象に混入する事故を防ぐ（rescoreAll は既存値を温存=skip するが、
        //     初回は温存対象が無いため anchor 化して whereNotExists の再選択も止める）。
        //     ガード式は下の calcScore が受け取る本文式と一致させる（件名のみになる時だけ発火）。
        $body = $email->body_text ?? strip_tags($email->body_html ?? '');
        if (trim($body) === '') {
            return $this->save($email, 0, ['excluded', 'body_purged'], 'rule', [], []);
        }

        // ② スコアリング
        [$score, $reasons, $bd] = $this->calcScore(
            $subject . "\n" . $body,
            $email
        );

        $score     = max(0, min(100, $score));
        $extracted = $this->extract($email, $withAttachment);

        return $this->save($email, $score, $reasons, 'rule', $extracted, $bd);
    }

    // ── プライベートメソッド ──────────────────────────────

    private function isExcluded(string $subject, string $from): bool
    {
        $from = mb_strtolower($from);
        foreach (self::EXCLUDE_SUBJECT as $kw) {
            if (str_contains($subject, $kw)) return true;
        }
        foreach (self::EXCLUDE_FROM as $kw) {
            if (str_contains(strtolower($from), $kw)) return true;
        }
        foreach (self::EXCLUDE_DOMAIN as $domain) {
            // 部分一致だと 'evil-aizen-sol.co.jp.attacker.tld' のような偽装で過剰除外される
            // (docs/730 §Low #29)。`@domain` で末尾一致に揃える。
            if (str_ends_with($from, '@' . $domain)) return true;
        }
        return false;
    }

    private function calcScore(string $text, Email $email): array
    {
        // $bd: 営業向けスコア内訳 [['label'=>表示名,'points'=>加点]]。点数ロジックは不変・記録のみ追加。
        $score   = 0;
        $reasons = [];
        $bd      = [];

        // [A] 明示ワード (+15)
        foreach (self::ENGINEER_A as $kw) {
            if (mb_stripos($text, $kw) !== false) {
                $score += 15;
                $reasons[] = "engineer_kw:{$kw}";
                $bd[] = ['label' => "技術者紹介の明示（{$kw}）", 'points' => 15];
                break;
            }
        }

        // [B] 稼働条件 (+10)
        foreach (self::ENGINEER_B as $kw) {
            if (mb_stripos($text, $kw) !== false) {
                $score += 10;
                $reasons[] = "availability:{$kw}";
                $bd[] = ['label' => "稼働条件（{$kw}）", 'points' => 10];
                break;
            }
        }

        // [C] 技術スタック (+3/件 max 20)
        $techHits = 0; $techList = [];
        foreach (self::TECH_STACK as $tech) {
            if (mb_stripos($text, $tech) !== false) {
                $techHits++;
                $reasons[] = "tech:{$tech}";
                $techList[] = $tech;
                if ($techHits * 3 >= 20) break;
            }
        }
        $techPts = min($techHits * 3, 20);
        $score += $techPts;
        if ($techPts > 0) {
            $bd[] = ['label' => '技術スタック' . ($techList ? '（' . implode('・', $techList) . '）' : ''), 'points' => $techPts];
        }

        // [D] 所属区分 (+10)
        foreach (self::AFFILIATION_KW as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score += 10;
                $reasons[] = "affiliation:{$kw}";
                $bd[] = ['label' => "所属区分（{$kw}）", 'points' => 10];
                break;
            }
        }

        // [E] 添付ファイルあり (+15)
        if ($email->attachments && $email->attachments->isNotEmpty()) {
            $score += 15;
            $reasons[] = 'has_attachment';
            $bd[] = ['label' => '添付（スキルシート等）あり', 'points' => 15];
        }

        return [$score, $reasons, $bd];
    }

    /**
     * @param bool $withAttachment 添付ファイルをClaudeで解析するか（rescoreAll時はfalse）
     */
    private function extract(Email $email, bool $withAttachment = true): array
    {
        $subject = $email->subject ?? '';
        // body_text が空文字('')の HTML 専用メールでも HTML 本文へフォールバックする（?? は null のみ）。
        $body    = ($email->body_text ?? '') !== ''
            ? $email->body_text
            : $this->htmlToText($email->body_html ?? '');

        // 無効なUTF-8バイト列を除去
        $subject = iconv('UTF-8', 'UTF-8//IGNORE', $subject) ?: '';
        $body    = iconv('UTF-8', 'UTF-8//IGNORE', $body)    ?: '';

        // 全角数字を半角へ正規化（'n'=数字のみ）。\d ベースの抽出（希望単価・年齢等）が
        // 「７５万円」等の全角表記を取りこぼすのを防ぐ。
        $subject = mb_convert_kana($subject, 'n');
        $body    = mb_convert_kana($body, 'n');
        $text    = $subject . "\n" . $body;

        [$priceMin, $priceMax] = $this->extractUnitPrice($text);

        $result = [
            'name'             => $this->extractName($text),
            'age'              => $this->extractAge($text),
            'unit_price_min'   => $priceMin,
            'unit_price_max'   => $priceMax,
            'affiliation_type' => $this->extractAffiliationType($text),
            'affiliation'      => $this->extractAffiliation($text),
            'available_from'   => $this->extractAvailableFrom($text),
            'nearest_station'  => $this->extractNearestStation($text),
            'skills'           => $this->extractSkills($text),
            'has_attachment'   => $email->attachments && $email->attachments->isNotEmpty(),
        ];

        // 添付ファイルがある場合はClaudeで解析してマージ（Claude優先）
        // rescoreAll時はスキップ（Gmail API呼び出しが多すぎてタイムアウトするため）
        if ($withAttachment && $result['has_attachment']) {
            try {
                $claudeData = $this->extractFromAttachments($email);
                if ($claudeData) {
                    foreach ($claudeData as $key => $val) {
                        if ($val !== null && $val !== '' && $val !== []) {
                            $result[$key] = $val;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // パース失敗は想定内（パスワード保護・破損ファイル等・Claude API障害）→ warningレベルで記録
                Log::warning("[EngineerMailScoring] 添付解析スキップ email_id={$email->id}: " . $e->getMessage());
            }
        }

        return $this->sanitizeUtf8($result);
    }

    /**
     * 添付解析なしで抽出結果を返す（バックフィルの安全な再抽出から呼ぶ公開ラッパ）。
     */
    public function extractFieldsWithoutAttachment(Email $email): array
    {
        return $this->extract($email, false);
    }

    /**
     * 抽出結果の文字列値から不正 UTF8 バイト列を除去する（バイト境界切れの multibyte 対策）。
     */
    private function sanitizeUtf8(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                // mb_scrub は warning を出さずに不正・不完全バイトを置換する
                // （iconv //IGNORE は末尾不完全 multibyte で warning→Laravel が例外化し中断）。
                $data[$k] = mb_scrub($v, 'UTF-8');
            } elseif (is_array($v)) {
                $data[$k] = $this->sanitizeUtf8($v);
            }
        }
        return $data;
    }

    /**
     * HTML 本文をプレーンテキスト化する。strip_tags は <style>/<script> の中身を残すため、
     * 先にブロックごと除去してから tags を落とし、HTML エンティティも復元する。
     */
    private function htmlToText(string $html): string
    {
        if ($html === '') return '';
        $html = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;
        $text = strip_tags($html);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * 添付ファイルをGmail APIから取得してClaudeで解析
     */
    private function extractFromAttachments(Email $email): ?array
    {
        $attachments = $email->attachments ?? collect();
        if ($attachments->isEmpty()) return null;

        // スキルシート対象の拡張子のみ（MIME type は信頼せず拡張子で判定）
        // zip / 画像 / 動画 等は除外
        $supportedExts = ['pdf', 'xlsx', 'xls', 'docx', 'doc'];
        $skipExts      = ['zip', 'gz', 'tar', 'jpg', 'jpeg', 'png', 'gif', 'mp4', 'mov', 'avi', 'csv', 'txt'];

        // 対象添付ファイルを選択（最初の1件）
        $target = null;
        foreach ($attachments as $att) {
            $ext = strtolower(pathinfo($att->filename, PATHINFO_EXTENSION));
            if (in_array($ext, $skipExts, true)) continue;
            if (in_array($ext, $supportedExts, true)) {
                $target = $att;
                break;
            }
        }
        if (!$target) return null;

        // GmailToken を tenant_id から取得
        $gmailToken = GmailToken::where('tenant_id', $email->tenant_id)->first();
        if (!$gmailToken) return null;

        // Gmail API から添付データ取得（fetchAttachmentData内でbase64デコード済みバイナリを返す）
        $gmailService = app(GmailService::class);
        $binary = $gmailService->fetchAttachmentData(
            $gmailToken,
            $email->gmail_message_id,
            $target->gmail_attachment_id
        );

        if (!$binary) return null;

        // 一時ファイルに書き込んでテキスト抽出
        $ext     = strtolower(pathinfo($target->filename, PATHINFO_EXTENSION));
        $tmpPath = tempnam(sys_get_temp_dir(), 'ems_') . '.' . $ext;
        file_put_contents($tmpPath, $binary);

        // Supabase Storage に永続保存（storage_path が未設定の場合のみ）
        if (empty($target->storage_path)) {
            try {
                $safeName   = preg_replace('/[^\w\-\.]/u', '_', pathinfo($target->filename, PATHINFO_FILENAME));
                $safeName   = preg_replace('/[^\x00-\x7F]/u', '', $safeName) ?: substr(md5($target->filename), 0, 8);
                $mimeType   = $target->mime_type ?: 'application/octet-stream';
                $storagePath = "attachments/{$email->tenant_id}/{$email->id}/{$safeName}.{$ext}";
                $storage    = app(SupabaseStorageService::class);
                $publicUrl  = $storage->uploadBinary($binary, $storagePath, $mimeType);
                $target->update(['storage_path' => $publicUrl]);
            } catch (\Throwable $e) {
                Log::debug("[EngineerMailScoring] 添付Storageアップロード失敗 email_id={$email->id}: " . $e->getMessage());
            }
        }

        unset($binary); // 即座に解放

        try {
            $text = $this->extractTextFromTempFile($tmpPath, $ext);
        } finally {
            @unlink($tmpPath);
        }

        if (empty(trim($text))) return null;

        // Claude で解析
        $claude    = app(ClaudeService::class);
        $extracted = $claude->extractSkillSheetInfo(mb_substr($text, 0, 8000));
        unset($text);

        // Claude の結果を engineer_mail_sources フィールドにマッピング
        return $this->mapClaudeResult($extracted);
    }

    /**
     * 一時ファイルからテキスト抽出（PDF / Excel / Word）
     */
    private function extractTextFromTempFile(string $path, string $ext): string
    {
        if ($ext === 'pdf') {
            if (filesize($path) > 5 * 1024 * 1024) {
                return '（ファイルサイズが大きいため添付解析をスキップしました）';
            }
            $parser = new \Smalot\PdfParser\Parser();
            return $parser->parseFile($path)->getText();
        }

        if (in_array($ext, ['xlsx', 'xls'], true)) {
            // 3MB超のファイルはOOM/タイムアウトリスクが高いためスキップ
            if (filesize($path) > 3 * 1024 * 1024) {
                return '（ファイルサイズが大きいため添付解析をスキップしました）';
            }
            // PhpSpreadsheet OOM 防御: 抽出スコープのみ memory_limit を引上げ
            $prevMem = ini_get('memory_limit');
            ini_set('memory_limit', '1G');
            $spreadsheet = null;
            try {
                $reader = SpreadsheetIOFactory::createReaderForFile($path);
                $reader->setReadDataOnly(true);
                $reader->setReadEmptyCells(false);
                $spreadsheet = $reader->load($path);
                $text = '';
                foreach (array_slice($spreadsheet->getAllSheets(), 0, 2) as $sheet) {
                    $text .= '=== ' . $sheet->getTitle() . " ===\n";
                    $rowCount = 0;
                    foreach ($sheet->getRowIterator() as $row) {
                        if (++$rowCount > 200) break;
                        $cells = [];
                        $iter  = $row->getCellIterator();
                        $iter->setIterateOnlyExistingCells(true);
                        foreach ($iter as $cell) {
                            $val = trim((string)$cell->getValue());
                            if ($val !== '') $cells[] = $val;
                        }
                        if (!empty($cells)) $text .= implode("\t", $cells) . "\n";
                    }
                }
                return $text;
            } finally {
                if ($spreadsheet !== null) {
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);
                }
                ini_set('memory_limit', $prevMem);
                gc_collect_cycles();
            }
        }

        if (in_array($ext, ['docx', 'doc'], true)) {
            $phpWord = WordIOFactory::load($path);
            $text    = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $text .= $this->extractWordElementText($element);
                }
            }
            return $text;
        }

        return '';
    }

    private function extractWordElementText(object $element): string
    {
        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun
            || $element instanceof \PhpOffice\PhpWord\Element\Paragraph) {
            $text = '';
            foreach ($element->getElements() as $child) {
                $text .= $this->extractWordElementText($child);
            }
            return $text . "\n";
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
            return $element->getText();
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
            $text = '';
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    foreach ($cell->getElements() as $el) {
                        $text .= $this->extractWordElementText($el) . "\t";
                    }
                }
                $text .= "\n";
            }
            return $text;
        }
        return '';
    }

    /**
     * Claude の extractSkillSheetInfo 結果を EngineerMailSource フィールドに変換
     */
    private function mapClaudeResult(array $data): array
    {
        // affiliation_type: Claudeの英語値→日本語表示値
        $affiliationMap = [
            'self'       => '自社正社員',
            'first_sub'  => '一社先正社員',
            'bp'         => 'BP',
            'bp_member'  => 'BP要員',
            'contract'   => '契約社員',
            'freelance'  => '個人事業主',
            'joining'    => '入社予定',
            'hiring'     => '採用予定',
        ];

        $affiliationType = null;
        if (!empty($data['affiliation_type'])) {
            $affiliationType = $affiliationMap[$data['affiliation_type']] ?? null;
        }

        // skills: [{name, experience_years}] → [name, name, ...]
        $skills = [];
        foreach ($data['skills'] ?? [] as $s) {
            if (!empty($s['name'])) $skills[] = $s['name'];
        }

        $priceMin = isset($data['desired_unit_price_min']) ? (int) $data['desired_unit_price_min'] : null;
        $priceMax = isset($data['desired_unit_price_max']) ? (int) $data['desired_unit_price_max'] : null;

        return [
            'name'             => $data['name']             ?? null,
            'age'              => isset($data['age']) ? (int) $data['age'] : null,
            'unit_price_min'   => $priceMin,
            'unit_price_max'   => $priceMax,
            'affiliation_type' => $affiliationType,
            'affiliation'      => $data['affiliation']      ?? null,
            'available_from'   => $data['available_from']   ?? null,
            'nearest_station'  => $data['nearest_station']  ?? null,
            'skills'           => $skills ?: null,
        ];
    }

    private function extractName(string $text): ?string
    {
        // 優先1: ■氏名■ 形式（次行に値）→ NA(32歳/女性) から括弧前を取得
        if (preg_match('/■氏名■\s*\n\s*([^\n（(]{1,20})/u', $text, $m)) {
            $name = trim(preg_replace('/[（(].*/u', '', $m[1]));
            if ($name !== '') return $name;
        }

        // 優先2: ■氏　名：AS(女性/24歳) 形式（同行、全角スペースあり）
        if (preg_match('/■氏[　\s]*名[：:]\s*([^■\n（(]{1,15})/u', $text, $m)) {
            $name = trim(preg_replace('/[（(].*/u', '', $m[1]));
            if ($name !== '') return $name;
        }

        // 優先2.5: 【氏　名】TY や 【氏名】山田太郎 形式（角括弧 + 全角空白許容）
        if (preg_match('/【\s*氏[\s　]*名\s*】[\s　]*([^\s\n　■【（(]{1,15})/u', $text, $m)) {
            $name = trim(preg_replace('/[（(].*/u', '', $m[1]));
            if ($name !== '') return $name;
        }

        // 次点: 氏名：XXX / 名 前：XXX / 【名 前】XXX 形式
        // ラベル内空白許容（"氏 名"、"名 前" 等）、括弧前まで取得
        if (preg_match('/(?:【\s*)?(?:氏[\s　]*名|技術者名|エンジニア名|名[\s　]*前)\s*[】]?[：:　\s]*([^\s\n　■【／\/]{1,20})/u', $text, $m)) {
            $name = trim($m[1]);
            // 括弧があれば除去 (例: NA(32歳) → NA)
            $name = preg_replace('/[（(].*/u', '', $name);
            if ($name !== '') return $name;
        }

        // 最終フォールバック: 行頭の「英字略称（年齢〜）」形式（"氏名"ラベル無し）
        // 例: K.M（57歳男性）、S.K（27歳/男性）、T.I（46歳・男性）、MN（女性/51歳）
        // 区切り文字や 【 の直後でもマッチ
        if (preg_match('/(?:^|\n)[\s　＝━─\*\-□【]*([A-Za-z][A-Za-z.]{1,5})[\s　][^（()]*?[（(][^）)]*\d{2,3}歳/u', $text, $m)) {
            $name = trim($m[1]);
            if ($name !== '') return $name;
        }
        // 同パターン（直後すぐ括弧）
        if (preg_match('/(?:^|\n)[\s　＝━─\*\-□【]*([A-Za-z][A-Za-z.]{1,5})[\s　]*[（(][^）)]*\d{2,3}歳/u', $text, $m)) {
            $name = trim($m[1]);
            if ($name !== '') return $name;
        }

        // 「要員情報」直後の英字略称＋☆地名（例: YM☆飯能）
        if (preg_match('/要員情報[\s　】\]]*[―ー\-=━─\s\n]*([A-Za-z]{1,5})☆/u', $text, $m)) {
            $name = trim($m[1]);
            if ($name !== '') return $name;
        }

        // 「【基本情報】K.S / 女性 / 28歳」「【基本情報】任.HY」型
        // / または改行の手前まで取得
        if (preg_match('/【基本情報】[\s　]*([^\s\n　／\/【]{1,15})/u', $text, $m)) {
            $name = trim($m[1]);
            if ($name !== '') return $name;
        }

        // 「弊社要員K.Sを提案」「弊社要員任.HYを提案」型
        if (preg_match('/弊社要員[\s　]*([A-Za-zぁ-んァ-ヶ一-龯]{1,2}\.?[A-Za-z.]{1,8})[を、]/u', $text, $m)) {
            $name = trim($m[1]);
            if ($name !== '') return $name;
        }

        // 区切り行の直後「技術者　XX」型 ("技術者" ラベル + 全角空白 + 名前)
        if (preg_match('/(?:^|\n)[\s　＝━─\*\-]*技術者[\s　]+([A-Za-zぁ-んァ-ヶ一-龯][A-Za-zぁ-んァ-ヶ一-龯.]{0,12})(?:[\s　／\/\n]|$)/u', $text, $m)) {
            $name = trim($m[1]);
            if ($name !== '') return $name;
        }

        // 「所 属： 弊社正社員（Y.N）」「所属：弊社個人事業主（K.M）」型
        if (preg_match('/所[\s　]*属[：:　\s]*弊社[^\s（(\n]+[（(]([A-Za-z][A-Za-z.]{1,8})[）)]/u', $text, $m)) {
            $name = trim($m[1]);
            if ($name !== '') return $name;
        }

        return null;
    }

    private function extractAge(string $text): ?int
    {
        // ■年齢■ 形式（次行に値）
        if (preg_match('/■年齢■\s*\n\s*(\d{1,3})/u', $text, $m)) {
            return (int) $m[1];
        }
        // 年齢：28 / 年齢: 28歳 / 28歳
        if (preg_match('/年齢[：:\s]*(\d{2,3})/u', $text, $m)) {
            return (int) $m[1];
        }
        // 男性 38歳 / 女性 30歳（氏名行の性別＋年齢。Ksync 等の【名　前】：KS 男性 38歳 形式）。
        // 性別を前置することで本文コメント中の「60歳で定年退職」等の誤検出を避ける。
        if (preg_match('/(?:男性|女性)[\s　]*(\d{2,3})\s*歳/u', $text, $m)) {
            return (int) $m[1];
        }
        // NA(32歳) / S.N(28歳/男性) / MN(女性/51歳) などの括弧内
        if (preg_match('/[（(][^）)]*?(\d{2,3})歳/u', $text, $m)) {
            return (int) $m[1];
        }
        // 満28歳
        if (preg_match('/満\s*(\d{2,3})\s*歳/u', $text, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * 希望単価（万円/月）を抽出して [min, max] で返す。
     * 記載なしの場合は [null, null]。
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function extractUnitPrice(string $text): array
    {
        // 円建て表記（"単金額：700,000〜750,000円" / "月額 700000円" 等）を万円に換算（÷10000）。
        // ラベル必須＋6桁以上(=10万円以上)に限定して誤検出（電話番号・年・社員番号等）を防ぐ。
        // 「70万円」等の万表記は $yen が 6-7桁/カンマ形のみなので素通りし、後続パターンが拾う。
        $yenLabel = '(?:単[　\s]*[価金](?:[　\s]*額)?|月額|月単価|希望単価|想定単価)';
        $yen      = '(\d{1,3}(?:,\d{3})+|\d{6,7})';
        // 範囲: 700,000〜750,000円
        if (preg_match('/' . $yenLabel . '(?:[（(][^）)]*[）)])?[：:\s　]*' . $yen . '\s*(?:円)?\s*[〜～~\-－]\s*' . $yen . '\s*円/u', $text, $m)) {
            $lo = (int) str_replace(',', '', $m[1]);
            $hi = (int) str_replace(',', '', $m[2]);
            if ($lo >= 100000 && $hi >= 100000) {
                return [intdiv($lo, 10000), intdiv($hi, 10000)];
            }
        }
        // 単一: 単金額：700,000円
        if (preg_match('/' . $yenLabel . '(?:[（(][^）)]*[）)])?[：:\s　]*' . $yen . '\s*円/u', $text, $m)) {
            $v = (int) str_replace(',', '', $m[1]);
            if ($v >= 100000) {
                $man = intdiv($v, 10000);
                return [$man, $man];
            }
        }

        // ■単金 / ■単　金 形式（■終端・改行・次行どちらも対応、範囲も対応）
        // 例: "■単金■\n60~70万円" / "■単　金：110万" / "■単金：〜80万"
        // (?:（…）)? でラベル直後の (税抜)/(税込) 等の丸括弧を読み飛ばす（"単金（税抜）：70万" 対応）。
        if (preg_match('/■単[　\s]*金(?:[（(][^）)]*[）)])?[■：:\s　]*\n?\s*(.*?)(?:\n|$)/u', $text, $m)) {
            $line = $m[1];
            // 範囲: 60~70万 / 60〜70万
            if (preg_match('/(\d{2,3})[万Mm]?[〜～~\-－](\d{2,3})/u', $line, $r)) {
                return [(int) $r[1], (int) $r[2]];
            }
            // 単一値: ~110万 / 110万
            if (preg_match('/[〜～~]?(\d{2,3})[万Mm]/u', $line, $r)) {
                $val = (int) $r[1];
                return [$val, $val];
            }
        }

        // ■単価 / ■希望単価 形式（■終端・改行・次行どちらも対応）
        if (preg_match('/■(?:単[　\s]*価|希望単価)(?:[（(][^）)]*[）)])?[■：:\s　]*\n?\s*(.*?)(?:\n|$)/u', $text, $m)) {
            $line = $m[1];
            if (preg_match('/(\d{2,3})[万Mm]?[〜～~\-－](\d{2,3})/u', $line, $r)) {
                return [(int) $r[1], (int) $r[2]];
            }
            if (preg_match('/[〜～~]?(\d{2,3})[万Mm]/u', $line, $r)) {
                $val = (int) $r[1];
                return [$val, $val];
            }
        }

        // 【単　価】：自動車領域160万円前後 形式（Ksync 等の全角スペース入りブラケットラベル）。
        // 値行から範囲/単一を抽出。複数行ある場合は最初の値行のみ。
        if (preg_match('/【\s*単[　\s]*価\s*】(?:[（(][^）)]*[）)])?[：:　\s]*(.*?)(?:\n|$)/u', $text, $m)) {
            $line = $m[1];
            if (preg_match('/(\d{2,3})[万Mm]?[〜～~\-－](\d{2,3})/u', $line, $r)) {
                return [(int) $r[1], (int) $r[2]];
            }
            if (preg_match('/[〜～~]?(\d{2,3})[万Mm]/u', $line, $r)) {
                $val = (int) $r[1];
                return [$val, $val];
            }
        }

        // 範囲パターン（ラベルなし）: 60〜80万, 60~80万円, 60-80万円
        if (preg_match('/(\d{2,3})[万Mm]?[〜～~\-－](\d{2,3})[万Mm円]/u', $text, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        // ラベル付き単一値（全角スペース対応）: 単　価：80万, 単金 110万, 単金（税抜）：70万
        if (preg_match('/(?:単[　\s]*価|単[　\s]*金|希望単価|想定単価|月単価|月額)(?:[（(][^）)]*[）)])?[：:\s　]*[〜～~]?(\d{2,3})[万Mm]/u', $text, $m)) {
            $val = (int) $m[1];
            return [$val, $val];
        }

        // 上限のみ: ～80万/月, 〜110万円
        if (preg_match('/[〜～](\d{2,3})[万Mm][円]?[\/／]?[月Mm]?/u', $text, $m)) {
            $val = (int) $m[1];
            return [null, $val];
        }

        return [null, null];
    }

    private function extractAffiliationType(string $text): ?string
    {
        // 優先: ■所属■ 形式
        if (preg_match('/■所属■\s*\n\s*([^\n]{1,30})/u', $text, $m)) {
            $val = trim($m[1]);
            foreach (self::AFFILIATION_MAP as $type => $keywords) {
                foreach ($keywords as $kw) {
                    if (mb_strpos($val, $kw) !== false) return $type;
                }
            }
        }

        // 次点: テキスト全体から検索
        foreach (self::AFFILIATION_MAP as $type => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strpos($text, $kw) !== false) {
                    return $type;
                }
            }
        }
        return null;
    }

    /**
     * 所属会社名（テキスト）を抽出する
     * 重複検出キー (email + name + affiliation) の affiliation 用。
     * affiliation_type が enum なのに対し、こちらは「株式会社XYZ」のような社名テキスト。
     */
    private function extractAffiliation(string $text): ?string
    {
        // 優先1: ■所属■ 形式（次行に値）
        if (preg_match('/■所属■\s*\n\s*([^\n]{1,60})/u', $text, $m)) {
            $val = $this->cleanAffiliation($m[1]);
            if ($val !== '') return $val;
        }

        // 優先2: 「所属：株式会社XX」 形式（同行）
        if (preg_match('/(?:所属|会社名|社名)[：:　\s]*([^■\n]{1,60})/u', $text, $m)) {
            $val = $this->cleanAffiliation($m[1]);
            if ($val !== '') return $val;
        }

        return null;
    }

    private function cleanAffiliation(string $raw): string
    {
        $val = trim($raw);
        // 末尾の説明文・括弧書きを切り捨て（「株式会社XX (BP)」→「株式会社XX」）
        $val = preg_replace('/[（(\[【].*$/u', '', $val) ?? $val;
        $val = trim($val);
        // フリーランス / 個人事業主 等は所属無しとして null 扱い
        if (preg_match('/^(フリーランス|個人事業主|個人|なし|無し|不明|―|-|−)$/u', $val)) {
            return '';
        }
        return $val;
    }

    private function extractAvailableFrom(string $text): ?string
    {
        // 優先1: ■稼働日■ / ■稼働■ 形式（次行に値）
        if (preg_match('/■(?:稼働日|稼働開始|稼働)[■\s]*\n\s*([^\n]{1,20})/u', $text, $m)) {
            $val = trim($m[1]);
            if ($val !== '') return $val;
        }

        // 優先2: ■稼　動：1月開始 形式（同行、全角スペースあり）
        if (preg_match('/■稼[　\s]*動[：:　\s]*([^■\n]{1,20})/u', $text, $m)) {
            $val = trim($m[1]);
            if ($val !== '') return $val;
        }

        // ラベル内に「稼働/参画/開始」を含む 【…】 を汎用処理する。
        // 【稼働】【稼働開始日】【稼働開始時期】【参画可能時期】【稼働可能日】等を一括でカバー。
        // 値は次の 【 か改行までに限定し、部分マッチで「】：…」やラベル記号を巻き込まない。
        // （汎用パターンより先に処理しないと「稼働開始」等で部分マッチし「日】即日」を捕捉してしまう）
        // 区切りに改行を含めない（値が空のラベルが次行の別ラベルを巻き込むのを防ぐ）。
        if (preg_match('/【[^】\n]*(?:稼[　\s]*働|参画|開始)[^】\n]*】[：:　 \t]*([^\n【]{1,20})/u', $text, $m)) {
            $val = trim($m[1]);
            if ($val !== '') return $val;
        }

        // 非ブラケットのラベル（同行）。値に 【 を含めない（直後の別ラベルを巻き込まないため）。
        // 区切りに改行を含めない（同上）。
        $patterns = [
            '/(?:稼働開始日?|稼働可能日?|稼働予定|参画時期|参画可能|開始時期)[：:　 \t]*([^■\n【：:]{2,20})/u',
            '/(?:即日|即稼働|即対応)/u',
        ];
        foreach ($patterns as $i => $pattern) {
            if (preg_match($pattern, $text, $m)) {
                if ($i === 1) return '即日';
                $val = trim($m[1]);
                if (mb_strlen($val) <= 20) return $val;
            }
        }
        return null;
    }

    private function extractNearestStation(string $text): ?string
    {
        // 優先: ■最寄■ / ■最寄り■ / ■最寄駅■ 形式（次行に値）
        if (preg_match('/■最寄[り駅]?■\s*\n\s*([^\n]{1,20})/u', $text, $m)) {
            $station = $this->cleanStation(trim($m[1]));
            if ($station !== '') return $station;
        }

        // 【最　寄】：川崎大師駅（神奈川県） 形式（Ksync 等の全角スペース入りブラケットラベル）。
        // 値は括弧/改行の手前まで。後続の貪欲な「xxx駅」フォールバックより先に拾う。
        if (preg_match('/【\s*最[　\s]*寄[り駅]*\s*】[：:　\s]*([^\n（(]{2,20})/u', $text, $m)) {
            $station = $this->cleanStation(trim($m[1]));
            if ($station !== '') return $station;
        }

        $patterns = [
            // 最寄駅：xxx / 最寄り駅：xxx / 最寄：xxx（駅 任意）
            '/最寄[り]?駅?[：:　\s]+([^■\n]{2,20})/u',
            // 居住地：xxx / 在住：xxx
            '/(?:居住地|在住)[：:　\s]*([^■\n]{2,20})/u',
            // xxx駅 (フォールバック：■やラベル記号【】：を含まないもののみ。
            //         ラベル「最寄駅」自体や "【最寄駅" を捕捉しないため記号を除外)
            '/([^■\s【】：:]{2,8}駅)/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $station = $this->cleanStation(trim($m[1]));
                if ($station !== '' && mb_strlen($station) <= 15) return $station;
            }
        }
        return null;
    }

    /**
     * 駅名の不要プレフィックス除去
     */
    private function cleanStation(string $station): string
    {
        $station = trim($station);
        // 先頭に残ったラベルブラケット断片を除去
        // 例: 【最寄】中野駅 / 【最寄駅】：三鷹駅 / 寄】：成瀬駅 / 】横浜駅 → 駅名のみ
        if (mb_strpos($station, '】') !== false) {
            $station = preg_replace('/^.*?】\s*[：:]?\s*/u', '', $station) ?? $station;
        }
        // 「最寄駅：」「寄：」「り：」「駅：」などのゴミプレフィックスを除去
        $station = preg_replace('/^(?:最寄り?駅?|り|寄|駅)\s*[：:]\s*/u', '', $station) ?? $station;
        // 路線名（「線」で終わるもの）を除去（例: JR山手線渋谷駅 → 渋谷駅）。
        // 「駅」「（」「空白」を跨がない（例: 京王堀之内駅（京王相模原線…）で駅名を食べない）。
        $station = preg_replace('/^(?:JR|東急|京急|小田急|東武|西武|京王|東京メトロ|メトロ|地下鉄|都営|京成|相鉄|阪急|阪神|近鉄|南海|名鉄)[^駅（(\s　]*?線[\s　]*/u', '', $station) ?? $station;
        // 会社名プレフィックスのみ除去（駅名は残す。例: JR新宿駅 → 新宿駅）。
        // 駅名の一部になり得る社名（京王堀之内駅/西武新宿駅/京成上野駅/近鉄名古屋駅 等）は
        // 除外し、駅名に現れない JR/メトロ/都営/地下鉄 のみに限定する。
        $station = preg_replace('/^(?:JR|東京メトロ|メトロ|都営|地下鉄)[\s　]*/u', '', $station) ?? $station;
        // 「…等／…他」+ 区切り(/) の明確なゴミ接頭のみ除去（路線名プレフィックスは維持する方針）。
        // 例: JR湘南新宿線等/浦和駅 → 浦和駅 / 等/大宮駅 → 大宮駅。
        // 「埼京線/板橋駅」(路線/駅) や「A駅/B駅」(併記) は温存する。
        $station = preg_replace('/^[^\/／駅]*?(?:等|他|ほか)[\/／][[:space:]　]*/u', '', $station) ?? $station;
        // 括弧以降を除去（例: 渋谷駅（JR） → 渋谷駅）
        $station = preg_replace('/[（(].*/u', '', $station) ?? $station;
        // 「駅」の後ろに区切り＋ゴミ（後続に駅を含まない）が続く場合は駅名までで切る。
        // 例: 中野駅・所属：弊社フリーランス → 中野駅 / 大宮駅　徒歩5分 → 大宮駅。
        // 後続が別の駅の場合（南草津駅/瀬田駅・赤羽駅・志茂駅）は併記として温存する。
        $station = preg_replace('/駅[・,、:：\/／\s　]+[^駅]*$/u', '駅', $station) ?? $station;
        return trim($station);
    }

    private function extractSkills(string $text): array
    {
        $found = [];
        foreach (self::TECH_STACK as $tech) {
            if ($this->skillFound($text, $tech)) {
                $found[] = $tech;
            }
        }
        return array_values(array_unique($found));
    }

    /**
     * スキルキーワードが「単語として」テキスト内に存在するか判定
     * 前後が英数字の場合は除外（MongoDB内の"go"等を誤検出しない）
     */
    private function skillFound(string $text, string $skill): bool
    {
        $escaped = preg_quote($skill, '/');
        return (bool) preg_match('/(?<![a-zA-Z0-9\/\.])' . $escaped . '(?![a-zA-Z0-9\/\.])/iu', $text);
    }

    /**
     * 単価レンジを解決する。本文抽出が空なら抽出済みスキルシート本文から救済する ([A])。
     * @return array{0:?int,1:?int} [unit_price_min, unit_price_max]
     */
    private function resolveUnitPriceRange(array $extracted, ?string $skillSheetText): array
    {
        $min = $extracted['unit_price_min'] ?? null;
        $max = $extracted['unit_price_max'] ?? null;
        if ($min === null && $max === null && $skillSheetText !== null && $skillSheetText !== '') {
            [$min, $max] = $this->extractUnitPrice($skillSheetText);
        }
        return [$min, $max];
    }

    /**
     * 単価チェックを反映した status を返す。$reasons は参照で更新する。
     * - 単価記載なし (no_unit_price): 除外せず review 止まり ([B]・手動トリアージ枠)
     * - 下限未満 (unit_price_too_low): 従来どおり除外
     */
    private function priceAdjustedStatus(int $score, array &$reasons, ?int $price): string
    {
        if ($price === null) {
            $reasons[] = 'no_unit_price';
        } elseif ($price < self::PRICE_MIN_FLOOR) {
            $reasons[] = 'unit_price_too_low';
            $reasons[] = 'excluded';
        }
        return match (true) {
            in_array('excluded', $reasons, true)      => 'excluded',
            in_array('no_unit_price', $reasons, true) => 'review',
            $score >= self::SCORE_OK                  => 'new',
            $score >= self::SCORE_REVIEW              => 'review',
            default                                   => 'excluded',
        };
    }

    private function save(Email $email, int $score, array $reasons, string $engine, array $extracted, array $breakdown = []): EngineerMailSource
    {
        // 添付スキルシート本文を先に抽出 (docs/480 §3.3)。未抽出なら 1 回だけ実行。
        // 単価救済 [A] に使うため price 判定より前に解決する。失敗しても保存は継続。
        $existing       = EngineerMailSource::where('email_id', $email->id)
            ->where('tenant_id', $email->tenant_id)->first();
        $skillSheetText = $existing?->parsed_skill_sheet_text;
        if ($skillSheetText === null) {
            try {
                $email->loadMissing('attachments');
                if ($email->attachments->isNotEmpty()) {
                    $extractor      = app(SkillSheetTextExtractor::class);
                    $skillSheetText = $extractor->extractFromAttachments($email->attachments) ?: null;
                }
            } catch (\Throwable $e) {
                Log::warning('[EngineerMailScoring] skill sheet extract failed', [
                    'email_id' => $email->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // 単価チェック（本文 → スキルシート本文の順で解決。記載なし=review / 下限未満=除外）
        [$priceMin, $priceMax]       = $this->resolveUnitPriceRange($extracted, $skillSheetText);
        $extracted['unit_price_min'] = $priceMin;
        $extracted['unit_price_max'] = $priceMax;
        $status = $this->priceAdjustedStatus($score, $reasons, $priceMin ?? $priceMax);

        $persist = array_merge($extracted, [
            'score'          => $score,
            'score_reasons'  => $reasons,
            'score_breakdown'=> $breakdown,
            'engine'         => $engine,
            'status'         => $status,
            'received_at'    => $email->received_at,
            'arrived_at'     => $email->arrived_at,
        ]);
        if ($skillSheetText !== null) {
            $persist['parsed_skill_sheet_text'] = $skillSheetText;
        }

        return EngineerMailSource::updateOrCreate(
            ['email_id' => $email->id, 'tenant_id' => $email->tenant_id],
            $persist
        );
    }
}
