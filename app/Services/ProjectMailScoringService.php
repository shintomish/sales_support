<?php

namespace App\Services;

use App\Models\Email;
use App\Models\ProjectMailSource;
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
    // ── ① 除外ワード ──────────────────────────────────────

    private const EXCLUDE_SUBJECT = [
        '配信停止', 'メルマガ', '広告', '請求書', 'お支払い',
        '正社員募集', '中途採用', 'ご挨拶', 'お知らせ',
    ];

    private const EXCLUDE_FROM = ['no-reply', 'noreply'];

    // 自社ドメイン（自社・当社営業担当のメールは案件対象外）
    private const EXCLUDE_DOMAIN = ['aizen-sol.co.jp'];

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
     */
    public function scorePending(?int $limit = null): int
    {
        $processedIds = ProjectMailSource::pluck('email_id')->all();

        $query = Email::where('category', 'project')
            ->whereNotIn('id', $processedIds)
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
     * 既存レコードを全件再スコアリング＋再抽出
     */
    public function rescoreAll(?int $limit = null): int
    {
        $query = ProjectMailSource::with('email')->whereNotNull('email_id');
        if ($limit !== null) $query->limit($limit);

        $count = 0;
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
                    $pms->update(array_merge($extracted, [
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
        return $count;
    }

    /**
     * 既存レコードの抽出情報だけを再計算（スコアは変えない）
     */
    public function reextractAll(?int $limit = null): int
    {
        $query = ProjectMailSource::with('email')
            ->whereNotNull('email_id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $count = 0;
        foreach ($query->get() as $pms) {
            if (!$pms->email) continue;
            try {
                $extracted = $this->extract($pms->email);
                $pms->update($extracted);
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
        $skipDomains = ['smoothcontact.com', 'gmail.com', 'yahoo.co.jp', 'hotmail.com'];
        if (in_array($domain, $skipDomains, true)) return $empty;

        // 判断済みレコードのみ集計（review = 未判断は除外）
        $rows = ProjectMailSource::where('tenant_id', $tenantId)
            ->whereNotIn('status', ['review'])
            ->whereHas('email', fn($q) => $q->where('from_address', 'like', '%@' . $domain))
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status != 'excluded' THEN 1 ELSE 0 END) as project_count
            ")
            ->first();

        $total        = (int) ($rows->total ?? 0);
        $projectCount = (int) ($rows->project_count ?? 0);

        // 最低5件のサンプルが必要
        if ($total < 5) return array_merge($empty, ['domain' => $domain, 'sample' => $total]);

        $rate  = $projectCount / $total;
        $bonus = match(true) {
            $rate >= 0.8 => 20,
            $rate <= 0.2 => -20,
            default      => 0,
        };

        return ['bonus' => $bonus, 'rate' => $rate, 'sample' => $total, 'domain' => $domain];
    }

    // ── 抽出（正規表現）──────────────────────────────────

    public function extract(Email $email): array
    {
        $subject  = $email->subject ?? '';
        $body     = $email->body_text ?? strip_tags($email->body_html ?? '');
        $fromName = $email->from_name ?? '';
        $fromAddr = $email->from_address ?? '';
        $text     = $subject . "\n" . $body;

        $isSmoothContact = str_contains($fromAddr, 'smoothcontact');

        // from_name を会社名・担当者名に分離する
        [$fromCompany, $fromPerson] = $this->parseFromName($fromName);

        return [
            'customer_name'    => $this->extractCustomerName($body, $fromName, $fromAddr, $fromCompany),
            'sales_contact'    => $this->extractSalesContact($body, $isSmoothContact) ?? $fromPerson,
            'phone'            => $this->extractPhone($body, $isSmoothContact),
            'title'            => $this->extractTitle($subject, $body),
            'required_skills'  => $this->extractSkills($text),
            'process'          => $this->extractProcess($text),
            'work_location'    => $this->extractLocation($text),
            'remote_ok'        => $this->extractRemoteOk($text),
            'unit_price_min'   => $this->extractPriceMin($text),
            'unit_price_max'   => $this->extractPriceMax($text),
            'start_date'       => $this->extractStartDate($text),
            'contract_type'    => $this->extractContractType($text),
            'age_limit'        => $this->extractAgeLimit($text),
            'nationality_ok'   => $this->extractNationalityOk($text),
            'supply_chain'     => $this->extractSupplyChain($text),
        ];
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
        if (preg_match(
            '/((?:株式|有限|合同|一般社団|一般財団)会社[\p{Han}\p{Hiragana}\p{Katakana}ー－\-・\w]+)(?:の[\p{Han}\p{Hiragana}\p{Katakana}ー\w]{1,20})?(?:と申し|でございます|営業部|の者)/u',
            $body, $m
        )) {
            return mb_substr(trim($m[1]), 0, 100);
        }
        // 後置パターン: 「〇〇株式会社の〇〇と申します」
        if (preg_match(
            '/([\p{Han}\p{Hiragana}\p{Katakana}ー－\-・\w]+(?:株式|有限|合同)会社)(?:の[\p{Han}\p{Hiragana}\p{Katakana}ー\w]{1,20})?(?:と申し|でございます|営業部|の者)/u',
            $body, $m
        )) {
            return mb_substr(trim($m[1]), 0, 100);
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
        if (preg_match('/^((?:株式|有限|合同|一般社団|一般財団)会社[\p{Han}\p{Hiragana}\p{Katakana}ー－\-・\w]*)[\s　]+(.{2,20})$/u', $name, $m)) {
            $company = trim($m[1]);
            $person  = trim($m[2]);
            return [$company, $this->looksLikePersonName($person) ? $person : null];
        }

        // 後置会社名 + スペース + 候補
        if (preg_match('/^([\p{Han}\p{Hiragana}\p{Katakana}ー－\-・\w]+(?:株式|有限|合同)会社)[\s　]+(.{2,20})$/u', $name, $m)) {
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
        $found = [];
        $allSkills = array_merge(self::TECH_LANG, self::TECH_INFRA, self::TECH_DB);
        foreach ($allSkills as $skill) {
            if (mb_stripos($text, $skill) !== false) {
                $found[] = $skill;
            }
        }
        return array_values(array_unique($found));
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
        // 「勤務地：〇〇」「場所：〇〇」「最寄駅：〇〇」
        if (preg_match('/(?:勤務地|就業場所|作業場所|場所)\s*[：:]\s*([^\n\r　]{2,30})/u', $text, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/(?:最寄[駅り]?)\s*[：:]\s*([^\n\r　]{2,20})/u', $text, $m)) {
            return trim($m[1]);
        }
        // 都道府県パターン
        if (preg_match('/([東西南北]?(?:東京|大阪|名古屋|横浜|福岡|仙台|札幌|神奈川|埼玉|千葉)[^\n\r　]{0,20})/u', $text, $m)) {
            return trim($m[1]);
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

    private function extractPriceMin(string $text): ?float
    {
        // 「60〜80万」「60万〜80万」のレンジ
        if (preg_match('/(\d{2,3})\s*万[円]?\s*[〜～~]\s*(\d{2,3})\s*万/u', $text, $m)) {
            return (float) min($m[1], $m[2]);
        }
        // 「〜80万」（上限のみ）
        if (preg_match('/[〜～~]\s*(\d{2,3})\s*万/u', $text, $m)) {
            return null; // 下限不明
        }
        // 単独「70万」
        if (preg_match('/(\d{2,3})\s*万[円]?/u', $text, $m)) {
            return (float) $m[1];
        }
        return null;
    }

    private function extractPriceMax(string $text): ?float
    {
        // レンジ
        if (preg_match('/(\d{2,3})\s*万[円]?\s*[〜～~]\s*(\d{2,3})\s*万/u', $text, $m)) {
            return (float) max($m[1], $m[2]);
        }
        // 「〜80万」
        if (preg_match('/[〜～~]\s*(\d{2,3})\s*万/u', $text, $m)) {
            return (float) $m[1];
        }
        // 単独
        if (preg_match('/(\d{2,3})\s*万[円]?/u', $text, $m)) {
            return (float) $m[1];
        }
        return null;
    }

    private function extractStartDate(string $text): ?string
    {
        if (preg_match('/(?:即日|即時|即スタート)/u', $text)) {
            return '即日';
        }
        // 「2026年5月」「2026/05」「5月〜」「6月上旬」
        if (preg_match('/(\d{4})\s*年\s*(\d{1,2})\s*月/u', $text, $m)) {
            return "{$m[1]}-{$m[2]}";
        }
        if (preg_match('/(\d{4})[\/\-](\d{1,2})/u', $text, $m)) {
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
            if (mb_stripos($text, $kw) !== false) {
                $langCount++;
                if ($langCount === 1) { $techScore += 10; $reasons[] = "lang:{$kw}"; }
                elseif ($langCount === 2) { $techScore += 5;  $reasons[] = "lang2:{$kw}"; break; }
            }
        }
        foreach (self::TECH_INFRA as $kw) {
            if (mb_stripos($text, $kw) !== false) { $techScore += 5; $reasons[] = "infra:{$kw}"; break; }
        }
        foreach (self::TECH_DB as $kw) {
            if (mb_stripos($text, $kw) !== false) { $techScore += 3; $reasons[] = "db:{$kw}"; break; }
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
            ], $extracted)
        );
    }
}
