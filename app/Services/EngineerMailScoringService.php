<?php

namespace App\Services;

use App\Models\Email;
use App\Models\EngineerMailSource;
use Illuminate\Support\Facades\Log;

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

    // 自社ドメイン
    private const EXCLUDE_DOMAIN = ['aizen-sol.co.jp'];

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
        'Java', 'Spring', 'SpringBoot', 'PHP', 'Laravel',
        'Python', 'Django', 'Flask', 'C#', '.NET',
        'JavaScript', 'TypeScript', 'React', 'Vue', 'Angular',
        'Ruby', 'Rails', 'Go', 'Golang', 'Swift', 'Kotlin',
        'AWS', 'EC2', 'RDS', 'S3', 'Lambda',
        'Azure', 'GCP', 'Docker', 'Kubernetes', 'Linux',
        'MySQL', 'PostgreSQL', 'Oracle', 'SQLServer', 'MongoDB', 'Redis',
    ];

    // [D] 所属区分ワード (+10)
    private const AFFILIATION_KW = [
        'BP', 'フリーランス', '自社社員', '下請け', '一社先', '個人事業主', '契約社員',
    ];

    private const SCORE_OK     = 60;
    private const SCORE_REVIEW = 40;

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
     * 未処理の技術者メールを一括スコアリング
     */
    public function scorePending(?int $limit = null): int
    {
        $processedIds = EngineerMailSource::pluck('email_id')->all();

        $query = Email::where('category', 'engineer')
            ->whereNotIn('id', $processedIds)
            ->with('attachments')
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
                Log::error("[EngineerMailScoring] email_id={$email->id} 失敗: " . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * 既存レコードを全件再スコアリング＋再抽出
     */
    public function rescoreAll(?int $limit = null): int
    {
        $query = EngineerMailSource::with(['email.attachments'])->whereNotNull('email_id');
        if ($limit !== null) $query->limit($limit);

        $count = 0;
        foreach ($query->get() as $ems) {
            if (!$ems->email) continue;
            try {
                $email   = $ems->email;
                $subject = $email->subject ?? '';
                $from    = $email->from_address ?? '';

                if ($this->isExcluded($subject, $from)) {
                    $ems->update(['score' => 0, 'score_reasons' => ['excluded'], 'status' => 'excluded']);
                } else {
                    $body = $email->body_text ?? strip_tags($email->body_html ?? '');
                    $text = $subject . "\n" . $body;

                    [$score, $reasons] = $this->calcScore($text, $email);
                    $score     = max(0, min(100, $score));
                    $extracted = $this->extract($email);
                    $status = match(true) {
                        $score >= self::SCORE_OK     => 'new',
                        $score >= self::SCORE_REVIEW => 'review',
                        default                      => 'excluded',
                    };
                    $ems->update(array_merge($extracted, [
                        'score'         => $score,
                        'score_reasons' => $reasons,
                        'engine'        => 'rule',
                        'status'        => $status,
                    ]));
                }
                $count++;
            } catch (\Throwable $e) {
                Log::error("[EngineerMailRescore] ems_id={$ems->id} 失敗: " . $e->getMessage());
            }
        }
        return $count;
    }

    /**
     * 1件スコアリング＋抽出して保存
     */
    public function score(Email $email): EngineerMailSource
    {
        $subject = $email->subject ?? '';
        $from    = $email->from_address ?? '';

        // ① 除外
        if ($this->isExcluded($subject, $from)) {
            return $this->save($email, 0, ['excluded'], 'rule', []);
        }

        // ② スコアリング
        [$score, $reasons] = $this->calcScore(
            $subject . "\n" . ($email->body_text ?? strip_tags($email->body_html ?? '')),
            $email
        );

        $score     = max(0, min(100, $score));
        $extracted = $this->extract($email);

        return $this->save($email, $score, $reasons, 'rule', $extracted);
    }

    // ── プライベートメソッド ──────────────────────────────

    private function isExcluded(string $subject, string $from): bool
    {
        foreach (self::EXCLUDE_SUBJECT as $kw) {
            if (str_contains($subject, $kw)) return true;
        }
        foreach (self::EXCLUDE_FROM as $kw) {
            if (str_contains(strtolower($from), $kw)) return true;
        }
        foreach (self::EXCLUDE_DOMAIN as $domain) {
            if (str_contains(strtolower($from), $domain)) return true;
        }
        return false;
    }

    private function calcScore(string $text, Email $email): array
    {
        $score   = 0;
        $reasons = [];

        // [A] 明示ワード (+15)
        foreach (self::ENGINEER_A as $kw) {
            if (mb_stripos($text, $kw) !== false) {
                $score += 15;
                $reasons[] = "engineer_kw:{$kw}";
                break;
            }
        }

        // [B] 稼働条件 (+10)
        foreach (self::ENGINEER_B as $kw) {
            if (mb_stripos($text, $kw) !== false) {
                $score += 10;
                $reasons[] = "availability:{$kw}";
                break;
            }
        }

        // [C] 技術スタック (+3/件 max 20)
        $techHits = 0;
        foreach (self::TECH_STACK as $tech) {
            if (mb_stripos($text, $tech) !== false) {
                $techHits++;
                $reasons[] = "tech:{$tech}";
                if ($techHits * 3 >= 20) break;
            }
        }
        $score += min($techHits * 3, 20);

        // [D] 所属区分 (+10)
        foreach (self::AFFILIATION_KW as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score += 10;
                $reasons[] = "affiliation:{$kw}";
                break;
            }
        }

        // [E] 添付ファイルあり (+15)
        if ($email->attachments && $email->attachments->isNotEmpty()) {
            $score += 15;
            $reasons[] = 'has_attachment';
        }

        return [$score, $reasons];
    }

    private function extract(Email $email): array
    {
        $subject = $email->subject ?? '';
        $body    = $email->body_text ?? strip_tags($email->body_html ?? '');

        // 無効なUTF-8バイト列を除去
        $subject = iconv('UTF-8', 'UTF-8//IGNORE', $subject) ?: '';
        $body    = iconv('UTF-8', 'UTF-8//IGNORE', $body)    ?: '';
        $text    = $subject . "\n" . $body;

        return [
            'name'             => $this->extractName($text),
            'affiliation_type' => $this->extractAffiliationType($text),
            'available_from'   => $this->extractAvailableFrom($text),
            'nearest_station'  => $this->extractNearestStation($text),
            'skills'           => $this->extractSkills($text),
            'has_attachment'   => $email->attachments && $email->attachments->isNotEmpty(),
        ];
    }

    private function extractName(string $text): ?string
    {
        // 優先: ■氏名■ 形式（次行に値）→ NA(32歳/女性) から括弧前を取得
        if (preg_match('/■氏名■\s*\n\s*([^\n（(]{1,20})/u', $text, $m)) {
            $name = trim(preg_replace('/[（(].*/u', '', $m[1]));
            if ($name !== '') return $name;
        }

        // 次点: 氏名：XXX 形式（担当者：は除外）
        if (preg_match('/(?:氏名|技術者名|エンジニア名|名前)[：:　\s]*([^\s\n　]{2,10})/u', $text, $m)) {
            $name = trim($m[1]);
            // 括弧があれば除去 (例: NA(32歳) → NA)
            $name = preg_replace('/[（(].*/u', '', $name);
            if ($name !== '') return $name;
        }

        return null;
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

    private function extractAvailableFrom(string $text): ?string
    {
        // 優先: ■稼働日■ 形式
        if (preg_match('/■(?:稼働日|稼働開始|稼働)[■\s]*\n\s*([^\n]{1,20})/u', $text, $m)) {
            $val = trim($m[1]);
            if ($val !== '') return $val;
        }

        $patterns = [
            '/(?:稼働開始|稼働可能日?|稼働予定|参画時期|参画可能|開始時期)[：:　\s]*([^\n]{2,20})/u',
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
        // 優先: ■最寄■ 形式（駅名がそのまま書かれている）
        if (preg_match('/■最寄[り駅]?■\s*\n\s*([^\n]{1,20})/u', $text, $m)) {
            $station = trim($m[1]);
            if ($station !== '') return $station;
        }

        $patterns = [
            '/(?:最寄[り]?駅|最寄駅|居住地|在住)[：:　\s]*([^\n]{2,20})/u',
            '/([^\s]{2,8}駅)/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $station = trim($m[1]);
                // 路線名プレフィックスを除去（例: JR山手線渋谷駅 → 渋谷駅）
                $station = preg_replace('/^(?:JR|東急|京急|小田急|東武|西武|京王|メトロ|地下鉄|都営)[^\s]*\s*/u', '', $station);
                if (mb_strlen($station) <= 15) return $station;
            }
        }
        return null;
    }

    private function extractSkills(string $text): array
    {
        $found = [];
        foreach (self::TECH_STACK as $tech) {
            if (mb_stripos($text, $tech) !== false) {
                $found[] = $tech;
            }
        }
        return array_values(array_unique($found));
    }

    private function save(Email $email, int $score, array $reasons, string $engine, array $extracted): EngineerMailSource
    {
        $status = match(true) {
            in_array('excluded', $reasons) => 'excluded',
            $score >= self::SCORE_OK       => 'new',
            $score >= self::SCORE_REVIEW   => 'review',
            default                        => 'excluded',
        };

        return EngineerMailSource::updateOrCreate(
            ['email_id' => $email->id, 'tenant_id' => $email->tenant_id],
            array_merge($extracted, [
                'score'         => $score,
                'score_reasons' => $reasons,
                'engine'        => $engine,
                'status'        => $status,
                'received_at'   => $email->received_at,
            ])
        );
    }
}
