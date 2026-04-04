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

    // ── ② 強ワード ────────────────────────────────────────

    private const STRONG_A = [
        '案件', '要員', '技術者募集', 'エンジニア募集',
        'SE募集', 'PG募集', '技術者紹介',
    ];

    private const STRONG_BC = [
        '単価', 'スキル', '勤務地', '最寄駅', '稼働', '開始',
        '準委任', '常駐', 'リモート',
    ];

    // ── ③ スコア辞書 ──────────────────────────────────────

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

    private const PROCESS_UPPER = ['要件定義', '基本設計', '詳細設計'];
    private const PROCESS_DEV   = ['開発', '実装', '製造'];
    private const PROCESS_OTHER = ['テスト', '保守', '運用'];

    private const PRICE_CONCRETE_PATTERN = '/\d{2,3}\s*万[円]?/u';
    private const PRICE_VAGUE  = ['スキル見合い', '応相談'];
    private const TIMING       = ['月', '人月', '即日', '長期'];
    private const LOCATION_KW  = [
        '東京', '大阪', '名古屋', '横浜', '品川', '渋谷', '新宿',
        '福岡', '仙台', '札幌', '在宅',
    ];

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

        // ② 強ワード
        if ($this->isStrongMatch($text)) {
            $extracted = $this->extract($email);
            return $this->save($email, 100, ['strong_keyword_match'], 'rule', $extracted);
        }

        // ③ スコア
        [$score, $reasons] = $this->calcScore($text);
        $extracted = $this->extract($email);

        return $this->save($email, $score, $reasons, 'rule', $extracted);
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

        return [
            'customer_name'    => $this->extractCustomerName($body, $fromName, $fromAddr),
            'sales_contact'    => $this->extractSalesContact($body, $isSmoothContact),
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

    private function extractCustomerName(string $body, string $fromName, string $fromAddr): ?string
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

        // ② 明示ラベル（クライアント：, エンド：, 常駐先：等）
        if (preg_match(
            '/(?:クライアント|エンド(?:先|クライアント)?|常駐先|顧客|発注元|取引先|企業名)\s*[：:]\s*([^\n\r　]{2,50})/u',
            $body, $m
        )) {
            return mb_substr(trim($m[1]), 0, 100);
        }

        // ③ from_name に会社名が含まれる場合
        if ($fromName) {
            if (preg_match('/((?:株式|有限|合同)会社[\p{Han}\p{Hiragana}\p{Katakana}ー－\-・\w]+)/u', $fromName, $m)) {
                return mb_substr(trim($m[1]), 0, 100);
            }
            if (preg_match('/([\p{Han}\p{Hiragana}\p{Katakana}ー－\-・\w]+(?:株式|有限|合同)会社)/u', $fromName, $m)) {
                return mb_substr(trim($m[1]), 0, 100);
            }
            $name = trim($fromName);
            if (mb_strlen($name) >= 4 && !preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}ー]{2,5}$/u', $name)) {
                return mb_substr($name, 0, 100);
            }
        }

        // ④ ドメインから推測
        if ($fromAddr && preg_match('/@([\w\-]+)\.(?:co\.jp|com|jp)/i', $fromAddr, $m)) {
            return $m[1];
        }

        return null;
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
        return false;
    }

    private function isStrongMatch(string $text): bool
    {
        $hasA = false;
        foreach (self::STRONG_A as $kw) {
            if (mb_strpos($text, $kw) !== false) { $hasA = true; break; }
        }
        if (!$hasA) return false;
        foreach (self::STRONG_BC as $kw) {
            if (mb_strpos($text, $kw) !== false) return true;
        }
        return false;
    }

    private function calcScore(string $text): array
    {
        $score = 0; $reasons = [];

        $techScore = 0;
        foreach (self::TECH_LANG as $kw) {
            if (mb_stripos($text, $kw) !== false) {
                $techScore = min($techScore + 20, 30); $reasons[] = "lang:{$kw}"; break;
            }
        }
        foreach (self::TECH_INFRA as $kw) {
            if (mb_stripos($text, $kw) !== false) {
                $techScore = min($techScore + 15, 30); $reasons[] = "infra:{$kw}"; break;
            }
        }
        foreach (self::TECH_DB as $kw) {
            if (mb_stripos($text, $kw) !== false) {
                $techScore = min($techScore + 10, 30); $reasons[] = "db:{$kw}"; break;
            }
        }
        $score += $techScore;

        foreach (self::PROCESS_UPPER as $kw) {
            if (mb_strpos($text, $kw) !== false) { $score += 15; $reasons[] = "process_upper:{$kw}"; break; }
        }
        foreach (self::PROCESS_DEV as $kw) {
            if (mb_strpos($text, $kw) !== false) { $score += 10; $reasons[] = "process_dev:{$kw}"; break; }
        }
        foreach (self::PROCESS_OTHER as $kw) {
            if (mb_strpos($text, $kw) !== false) { $score += 5; $reasons[] = "process_other:{$kw}"; break; }
        }

        if (preg_match(self::PRICE_CONCRETE_PATTERN, $text)) {
            $score += 20; $reasons[] = 'price_concrete';
        }
        foreach (self::PRICE_VAGUE as $kw) {
            if (mb_strpos($text, $kw) !== false) { $score -= 10; $reasons[] = "price_vague:{$kw}"; break; }
        }
        foreach (self::TIMING as $kw) {
            if (mb_strpos($text, $kw) !== false) { $score += 10; $reasons[] = "timing:{$kw}"; break; }
        }
        foreach (self::LOCATION_KW as $kw) {
            if (mb_strpos($text, $kw) !== false) { $score += 10; $reasons[] = "location:{$kw}"; break; }
        }

        return [max(0, $score), $reasons];
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
