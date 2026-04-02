<?php

namespace App\Services;

use App\Models\Email;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class EmailClassificationService
{
    // 本文からURLを抽出する正規表現
    private const URL_PATTERN = '/https?:\/\/[^\s\x{3000}"\'<>「」【】）\)]+/u';

    // 件名にこれらが含まれる場合は技術者メールと判定（案件メールに見えても実態は人材紹介）
    private const ENGINEER_SUBJECT_KEYWORDS = [
        '人材', '人財', '正社員', 'プロパー', '要員',
        'スキルシート', '経歴書', '職務経歴', 'フリーランス',
        'ご紹介', '弊社直', '弊社要員', '弊社社員',
    ];

    /**
     * 全メールを再分類する（ルール変更後の一括更新用）
     * @return int 分類件数
     */
    public function reclassifyAll(): int
    {
        // 登録済みメールは除外（データ整合性を守るため）
        Email::whereNull('registered_at')->update(['category' => null, 'classified_at' => null]);
        return $this->classifyPending();
    }

    /**
     * 未分類メールを一括分類する
     * @param int|null $limit nullで全件、数値で上限
     * @return int 分類件数
     */
    public function classifyPending(?int $limit = null): int
    {
        $query = Email::whereNull('category')
            ->with('attachments')
            ->orderByDesc('received_at');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $emails = $query->get();

        $count = 0;
        foreach ($emails as $email) {
            try {
                $this->classify($email);
                $count++;
            } catch (\Throwable $e) {
                Log::error("[EmailClassification] email_id={$email->id} 失敗: " . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * 1件のメールを分類してDBを更新する
     */
    public function classify(Email $email): void
    {
        [$category, $reason, $urls] = $this->determineCategory($email);

        $email->update([
            'category'       => $category,
            'classified_at'  => Carbon::now(),
            'extracted_data' => [
                'classification_reason' => $reason,
                'urls'                  => $urls,
                'has_attachments'       => $email->attachments->isNotEmpty(),
            ],
        ]);
    }

    /**
     * 分類ルール（返り値: [category, reason, urls]）
     *
     * 優先順位:
     *   1. 添付ファイルあり               → engineer
     *   2. 件名に【技術者情報】           → engineer
     *   3. 件名に【案件情報】             → project
     *   4. 本文にURLあり（件名なし含む）  → project
     *   5. 本文のみ（URLなし）            → project
     */
    private function determineCategory(Email $email): array
    {
        $subject = $email->subject ?? '';
        $body    = $email->body_text ?? $email->body_html ?? '';
        $urls    = $this->extractUrls($body);

        // 1. 添付ファイルあり
        if ($email->attachments->isNotEmpty()) {
            return ['engineer', 'has_attachment', $urls];
        }

        // 2. 件名【技術者情報】
        if (mb_strpos($subject, '【技術者情報】') !== false) {
            return ['engineer', 'subject_engineer_keyword', $urls];
        }

        // 3. 件名に人材系キーワード（人材・人財・正社員・プロパー・要員 等）
        foreach (self::ENGINEER_SUBJECT_KEYWORDS as $kw) {
            if (mb_strpos($subject, $kw) !== false) {
                return ['engineer', 'subject_human_keyword:' . $kw, $urls];
            }
        }

        // 4. 件名【案件情報】
        if (mb_strpos($subject, '【案件情報】') !== false) {
            return ['project', 'subject_project_keyword', $urls];
        }

        // 5. 本文にURLあり（件名なし・URLのみ含む）
        if (!empty($urls)) {
            return ['project', 'body_url', $urls];
        }

        // 6. 本文のみ
        return ['project', 'body_text_only', []];
    }

    /**
     * 本文からURLを抽出して配列で返す
     */
    private function extractUrls(string $body): array
    {
        if (empty($body)) {
            return [];
        }

        preg_match_all(self::URL_PATTERN, $body, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }
}
