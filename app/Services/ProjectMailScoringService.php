<?php

namespace App\Services;

use App\Models\Email;
use App\Models\ProjectMailSource;
use Illuminate\Support\Facades\Log;

/**
 * 案件メール判定・スコアリングサービス
 *
 * emails テーブル（保管庫）の category='project' メールを対象に
 * 以下のフローで案件業務エンジン（project_mail_sources）へ取り込む:
 *
 *   ① 除外判定（即NG）
 *   ② 強ワード判定（即OK）
 *   ③ スコア判定（グレーゾーン）
 *
 * 将来の AI 差し替えを見越し、判定結果は
 * { score, reasons[], engine } の共通形式で保存する。
 */
class ProjectMailScoringService
{
    // ── ① 除外ワード ──────────────────────────────────────

    private const EXCLUDE_SUBJECT = [
        '配信停止', 'メルマガ', '広告', '請求書', 'お支払い',
        '正社員募集', '中途採用', 'ご挨拶', 'お知らせ',
    ];

    private const EXCLUDE_FROM = ['no-reply', 'noreply'];

    // ── ② 強ワード（A群 AND B/C群 で即OK）──────────────────

    private const STRONG_A = [
        '案件', '要員', '技術者募集', 'エンジニア募集',
        'SE募集', 'PG募集', '技術者紹介',
    ];

    private const STRONG_BC = [
        // B群（条件系）
        '単価', 'スキル', '勤務地', '最寄駅', '稼働', '開始',
        // C群（契約系）
        '準委任', '常駐', 'リモート',
    ];

    // ── ③ スコア辞書 ──────────────────────────────────────

    /** 言語/FW（上限30点） */
    private const TECH_LANG = [
        'Java', 'Spring', 'SpringBoot', 'PHP', 'Laravel',
        'Python', 'Django', 'Flask', 'C#', '.NET',
        'JavaScript', 'TypeScript', 'React', 'Vue', 'Angular',
        'Ruby', 'Rails', 'Go', 'Golang', 'Swift', 'Kotlin',
    ];

    /** インフラ/クラウド */
    private const TECH_INFRA = [
        'AWS', 'EC2', 'RDS', 'S3', 'Lambda',
        'Azure', 'GCP', 'Docker', 'Kubernetes', 'Linux',
    ];

    /** DB */
    private const TECH_DB = [
        'MySQL', 'PostgreSQL', 'Oracle', 'SQLServer', 'MongoDB', 'Redis',
    ];

    /** 上流工程 */
    private const PROCESS_UPPER = ['要件定義', '基本設計', '詳細設計'];

    /** 開発工程 */
    private const PROCESS_DEV = ['開発', '実装', '製造'];

    /** その他工程 */
    private const PROCESS_OTHER = ['テスト', '保守', '運用'];

    /** 金額（具体） */
    private const PRICE_CONCRETE_PATTERN = '/\d{2,3}\s*万[円]?/u';

    /** 金額（曖昧：減点） */
    private const PRICE_VAGUE = ['スキル見合い', '応相談'];

    /** 期間・稼働 */
    private const TIMING = ['月', '人月', '即日', '長期'];

    /** 場所 */
    private const LOCATION = [
        '東京', '大阪', '名古屋', '横浜', '品川', '渋谷', '新宿',
        '福岡', '仙台', '札幌', '在宅',
    ];

    // ── 閾値 ──────────────────────────────────────────────

    private const SCORE_OK       = 60;
    private const SCORE_REVIEW   = 40;

    // ── 公開メソッド ──────────────────────────────────────

    /**
     * 未処理の project カテゴリメールを一括スコアリング
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
     * 1件のメールをスコアリングして project_mail_sources に保存
     */
    public function score(Email $email): ProjectMailSource
    {
        $subject = $email->subject ?? '';
        $body    = $email->body_text ?? strip_tags($email->body_html ?? '');
        $from    = $email->from_address ?? '';
        $text    = $subject . ' ' . $body;

        // ① 除外判定
        if ($this->isExcluded($subject, $from)) {
            return $this->save($email, 0, ['excluded'], 'rule');
        }

        // ② 強ワード判定
        if ($this->isStrongMatch($text)) {
            return $this->save($email, 100, ['strong_keyword_match'], 'rule');
        }

        // ③ スコア判定
        [$score, $reasons] = $this->calcScore($text);

        return $this->save($email, $score, $reasons, 'rule');
    }

    // ── 内部メソッド ──────────────────────────────────────

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
        $score   = 0;
        $reasons = [];

        // 技術ワード（上限30点）
        $techScore = 0;
        foreach (self::TECH_LANG as $kw) {
            if (mb_stripos($text, $kw) !== false) {
                $techScore = min($techScore + 20, 30);
                $reasons[] = "lang:{$kw}";
                break;
            }
        }
        foreach (self::TECH_INFRA as $kw) {
            if (mb_stripos($text, $kw) !== false) {
                $techScore = min($techScore + 15, 30);
                $reasons[] = "infra:{$kw}";
                break;
            }
        }
        foreach (self::TECH_DB as $kw) {
            if (mb_stripos($text, $kw) !== false) {
                $techScore = min($techScore + 10, 30);
                $reasons[] = "db:{$kw}";
                break;
            }
        }
        $score += $techScore;

        // 工程ワード
        foreach (self::PROCESS_UPPER as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score += 15; $reasons[] = "process_upper:{$kw}"; break;
            }
        }
        foreach (self::PROCESS_DEV as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score += 10; $reasons[] = "process_dev:{$kw}"; break;
            }
        }
        foreach (self::PROCESS_OTHER as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score += 5; $reasons[] = "process_other:{$kw}"; break;
            }
        }

        // 金額（具体）
        if (preg_match(self::PRICE_CONCRETE_PATTERN, $text)) {
            $score += 20; $reasons[] = 'price_concrete';
        }

        // 金額（曖昧：減点）
        foreach (self::PRICE_VAGUE as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score -= 10; $reasons[] = "price_vague:{$kw}"; break;
            }
        }

        // 期間・稼働
        foreach (self::TIMING as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score += 10; $reasons[] = "timing:{$kw}"; break;
            }
        }

        // 場所
        foreach (self::LOCATION as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score += 10; $reasons[] = "location:{$kw}"; break;
            }
        }

        return [max(0, $score), $reasons];
    }

    private function save(Email $email, int $score, array $reasons, string $engine): ProjectMailSource
    {
        // スコアから status を決定
        $status = match(true) {
            $score === 0              => 'excluded',
            $score >= self::SCORE_OK  => 'new',
            $score >= self::SCORE_REVIEW => 'review',
            default                   => 'excluded',
        };

        return ProjectMailSource::updateOrCreate(
            ['email_id' => $email->id],
            [
                'tenant_id'   => $email->tenant_id,
                'score'        => $score,
                'score_reasons'=> $reasons,
                'engine'       => $engine,
                'status'       => $status,
                'received_at'  => $email->received_at,
            ]
        );
    }
}
