<?php

namespace App\Services;

use App\Models\Email;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class EmailClassificationService
{
    // 本文からURLを抽出する正規表現
    private const URL_PATTERN = '/https?:\/\/[^\s\x{3000}"\'<>「」【】）\)]+/u';

    // 件名にこれらが含まれる場合は案件メールと判定（人材系キーワードに優先する）。
    // 例: 「Ruby要員募集」「人材を探しています」は案件側が技術者を募集する案件メール
    private const PROJECT_SUBJECT_KEYWORDS_PRIORITY = [
        '要員募集', '要員募', '要員探', '要員を探',
        '人材募集', '人材を募集', '人材を探',
    ];

    // 件名にこれらが含まれる場合は技術者メールと判定（案件メールに見えても実態は人材紹介）
    private const ENGINEER_SUBJECT_KEYWORDS = [
        '人材', '人財', '正社員', 'プロパー', '要員',
        'スキルシート', '経歴書', '職務経歴', 'フリーランス',
        'ご紹介', '弊社直', '弊社要員', '弊社社員',
        '直個人', '注力個人',
    ];

    // 本文にこれらが含まれる場合は案件メールと判定（engineer キーワードより優先）
    private const PROJECT_BODY_KEYWORDS_PRIORITY = [
        '対応可能な人材がいらっしゃいましたら',
        '見合う要員様がいらっしゃいましたら',
        '対応可能な要員',
        '対応可能な技術者がいらっしゃいましたら',
        '案件情報のご紹介',
        '注力しております案件をご紹介',
        '案件のご紹介でございます',
        '案件をご紹介させて頂きます',
        '案件をご紹介させていただきます',
        '案件のご紹介となります',
        '下記案件をご紹介',
        'ご対応可能な方が',
    ];

    // 件名がイニシャル＠地名パターンの場合は技術者メールと判定
    // 例: 【Python】IY＠京王多摩センター【リモート/5月～】
    private const ENGINEER_SUBJECT_PATTERN = '/[A-Z]{2,3}＠/u';

    // 件名に年齢＋単価パターンがある場合は技術者メールと判定
    // 例: 【AWS・28歳】インフラ歴6年／70万
    private const ENGINEER_AGE_PRICE_PATTERN = '/\d{2}歳.*[\/／]\d{2,3}万/u';

    // 件名にこれらが含まれる場合は other（営業挨拶／会社紹介）と判定し、
    // 案件・技術者の判定をスキップする
    private const OTHER_SUBJECT_KEYWORDS = [
        '企業向け問い合わせ',
        'お問い合わせフォーム',
    ];

    // 本文にこれらが含まれる場合は other（営業挨拶／会社紹介）と判定し、
    // engineer/project 判定より優先する
    private const OTHER_BODY_KEYWORDS = [
        'ご挨拶もかねて',
        'ご挨拶も兼ねて',
        '会社紹介に伺い',
        'ご挨拶に伺い',
        'ご協業のお付き合い',
    ];

    // 本文にこれらが含まれる場合は技術者メールと判定
    private const ENGINEER_BODY_KEYWORDS = [
        '弊社要員をご紹介',
        '弊社社員をご紹介',
        '弊社エンジニアをご紹介',
        '弊社技術者をご紹介',
        '要員のご紹介',
        'スキルシートを添付',
        '経歴書を添付',
        '技術者情報を送付',
        // 「技術者をご紹介させて」「技術者をご紹介をさせて」など中間助詞のゆらぎを吸収
        '技術者をご紹介',
        '営業中の技術者',
        // 「弊社所属で注力している要員情報になります／見合う案件ございましたら…ご紹介
        // いただけますと幸いです」型の技術者紹介メールを拾うための追加キーワード。
        '要員情報',
        '見合う案件ございましたら',
        '弊社所属',
        // 「Kotlinエンジニアのご紹介となります／案件ございましたらご紹介頂けますと」型。
        'エンジニアのご紹介',
        '案件ございましたらご紹介頂け',
        // 技術者プロフィール票特有のラベル（『■氏名』『■最寄駅』『■稼働開始』）
        '■氏名',
        '■最寄駅',
        '■稼働開始',
        // 「現在営業中の下記人材のご紹介をさせていただきます」型の個人事業主紹介
        // (株式会社Ksync 等。件名【注力個人】+ 本文にスキルシート Google Docs URL を含むため、
        // 技術者キーワードが無いと step6 body_url で project 誤判定される)。
        // 「営業中の人材」「紹介をさせて(=送り手が紹介する側)」は技術者メール固有の表現で過剰マッチしない。
        '営業中の下記人材',
        '人材のご紹介をさせて',
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
        if ($emails->isEmpty()) {
            return 0;
        }

        // 個別 UPDATE のループは Sentry の N+1 検出に引っかかるため、
        // PostgreSQL の `UPDATE ... FROM (VALUES ...)` で 1 文に集約する。
        //
        // - INSERT を使わないので NOT NULL 制約 (tenant_id 等) を踏まない
        // - 各行の category / extracted_data が異なっても OK
        // - Eloquent イベントはバイパスするが Email モデルに observer 無し
        $now  = Carbon::now()->toDateTimeString();
        $placeholders = [];
        $bindings = [];
        $count = 0;
        foreach ($emails as $email) {
            try {
                [$category, $reason, $urls] = $this->determineCategory($email);
                $extractedJson = json_encode([
                    'classification_reason' => $reason,
                    'urls'                  => $urls,
                    'has_attachments'       => $email->attachments->isNotEmpty(),
                ], JSON_UNESCAPED_UNICODE);
                $placeholders[] = '(?, ?, ?, ?, ?)';
                $bindings[]     = $email->id;
                $bindings[]     = $category;
                $bindings[]     = $now;
                $bindings[]     = $extractedJson;
                $bindings[]     = $now;
                $count++;
            } catch (\Throwable $e) {
                Log::error("[EmailClassification] email_id={$email->id} 失敗: " . $e->getMessage());
            }
        }

        if (!empty($placeholders)) {
            $values = implode(',', $placeholders);
            \Illuminate\Support\Facades\DB::update(
                "UPDATE emails AS e SET
                    category       = v.category::varchar,
                    classified_at  = v.classified_at::timestamp,
                    extracted_data = v.extracted_data::jsonb,
                    updated_at     = v.updated_at::timestamp
                 FROM (VALUES {$values})
                 AS v(id, category, classified_at, extracted_data, updated_at)
                 WHERE e.id = v.id::bigint",
                $bindings
            );
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
     *   1. 添付ファイルあり                          → engineer
     *   2. 件名に【技術者情報】                      → engineer
     *   3. 件名に project 優先キーワード（要員募集等）→ project（人材系より優先）
     *   3.5 本文に project 優先キーワード             → project（engineer body より優先）
     *   4. 件名に人材系キーワード                    → engineer
     *   ...
     */
    private function determineCategory(Email $email): array
    {
        $subject = $email->subject ?? '';
        $body    = $email->body_text ?? $email->body_html ?? '';
        $urls    = $this->extractUrls($body);

        // 0. 自社ドメインの個人/システム系アドレスは除外（営業担当の返信・社内通知）
        //    outsource@ は外部SESパートナーからのML配信リレーで本物の案件情報を含むため除外しない
        $fromAddress = strtolower($email->from_address ?? '');
        if (str_ends_with($fromAddress, '@aizen-sol.co.jp')
            && !str_starts_with($fromAddress, 'outsource@')) {
            return ['other', 'own_domain', $urls];
        }

        // 0.5. 営業挨拶／会社紹介メール（cc に outsource@ を含む B2B 営業フォーム経由など）
        //      → other。engineer/project の本文キーワード判定より先に振り分ける。
        //      件名キーワード優先
        foreach (self::OTHER_SUBJECT_KEYWORDS as $kw) {
            if (mb_strpos($subject, $kw) !== false) {
                return ['other', 'subject_other_keyword:' . $kw, $urls];
            }
        }
        foreach (self::OTHER_BODY_KEYWORDS as $kw) {
            if (mb_strpos($body, $kw) !== false) {
                return ['other', 'body_other_keyword:' . $kw, $urls];
            }
        }

        // 1. 添付ファイルあり
        if ($email->attachments->isNotEmpty()) {
            return ['engineer', 'has_attachment', $urls];
        }

        // 2. 件名【技術者情報】
        if (mb_strpos($subject, '【技術者情報】') !== false) {
            return ['engineer', 'subject_engineer_keyword', $urls];
        }

        // 3. 件名に project 優先キーワード（要員募集／要員探／人材募集 等）→ 案件メール
        //    「要員募集」「人材募集」型は案件側からの技術者要請なので project に分類する
        foreach (self::PROJECT_SUBJECT_KEYWORDS_PRIORITY as $kw) {
            if (mb_strpos($subject, $kw) !== false) {
                return ['project', 'subject_project_priority:' . $kw, $urls];
            }
        }

        // 3.5. 本文に project 優先キーワード（対応可能な人材／要員様 等）
        //      engineer 本文キーワードより先にチェック（"要員のご紹介" などとの優先度逆転を防ぐ）
        foreach (self::PROJECT_BODY_KEYWORDS_PRIORITY as $kw) {
            if (mb_strpos($body, $kw) !== false) {
                return ['project', 'body_project_priority:' . $kw, $urls];
            }
        }

        // 4. 件名に人材系キーワード（人材・人財・正社員・プロパー・要員 等）
        foreach (self::ENGINEER_SUBJECT_KEYWORDS as $kw) {
            if (mb_strpos($subject, $kw) !== false) {
                return ['engineer', 'subject_human_keyword:' . $kw, $urls];
            }
        }

        // 3.5. 件名にイニシャル＠地名パターン（例: IY＠京王多摩センター）
        if (preg_match(self::ENGINEER_SUBJECT_PATTERN, $subject)) {
            return ['engineer', 'subject_initial_location', $urls];
        }

        // 3.6. 件名に年齢＋単価パターン（例: 28歳／...／70万）
        if (preg_match(self::ENGINEER_AGE_PRICE_PATTERN, $subject)) {
            return ['engineer', 'subject_age_price', $urls];
        }

        // 4. 本文に技術者キーワード
        foreach (self::ENGINEER_BODY_KEYWORDS as $kw) {
            if (mb_strpos($body, $kw) !== false) {
                return ['engineer', 'body_engineer_keyword:' . $kw, $urls];
            }
        }

        // 5. 件名【案件情報】
        if (mb_strpos($subject, '【案件情報】') !== false) {
            return ['project', 'subject_project_keyword', $urls];
        }

        // 6. 本文にURLあり（件名なし・URLのみ含む）
        if (!empty($urls)) {
            return ['project', 'body_url', $urls];
        }

        // 7. 本文のみ
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
