<?php

namespace Tests\Unit\Services;

use App\Models\Email;
use App\Services\EmailClassificationService;
use Tests\TestCase;

/**
 * EmailClassificationService の分類ロジックを検証する Unit テスト。
 *
 * 主目的: 「技術者紹介メールが project に誤分類される」回帰を防ぐ。
 * 報告ケース（2026-05-03）を再現し、追加した本文キーワードで engineer 判定
 * されることを担保する。
 */
class EmailClassificationServiceTest extends TestCase
{
    private EmailClassificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
        $this->service = new EmailClassificationService();
    }

    private function makeEmail(string $subject, string $body, ?string $fromAddress = null): Email
    {
        return Email::factory()->create([
            'tenant_id'    => $this->authUser->tenant_id,
            'subject'      => $subject,
            'body_text'    => $body,
            'from_address' => $fromAddress ?? 'sender@external.example.com',
        ]);
    }

    // ─── 既存ルール（リグレッション）───

    public function test_classifies_as_engineer_when_subject_has_human_keyword(): void
    {
        $email = $this->makeEmail('【要員情報】Java開発者', '本文');

        $this->service->classify($email);

        $this->assertSame('engineer', $email->fresh()->category);
    }

    public function test_classifies_as_engineer_when_subject_has_age_price_pattern(): void
    {
        $email = $this->makeEmail('【AWS・28歳】インフラ歴6年／70万', '本文');

        $this->service->classify($email);

        $this->assertSame('engineer', $email->fresh()->category);
    }

    public function test_classifies_as_project_when_only_body_text(): void
    {
        $email = $this->makeEmail('案件のご依頼', 'お疲れ様です。下記案件についてご検討ください。');

        $this->service->classify($email);

        $this->assertSame('project', $email->fresh()->category);
    }

    public function test_skips_own_domain_to_other(): void
    {
        $email = $this->makeEmail('社内通知', '本文', 'shintomi.sh@aizen-sol.co.jp');

        $this->service->classify($email);

        $this->assertSame('other', $email->fresh()->category);
    }

    // ─── 追加キーワード（本セッションで追加）───

    /** 報告ケース #1 */
    public function test_classifies_as_engineer_when_body_has_youin_jouhou_phrase(): void
    {
        $email = $this->makeEmail(
            '金融・半導体分野での約2年にわたるSE（テスター）経験を融合させたエンジニア',
            "株式会社アイゼン・ソリューション\nご担当者さま\nお世話になっております。\n株式会社エンジニアのミカタの須永です。\n\n弊社所属で注力している要員情報になります。\n見合う案件ございましたら是非ご紹介いただけますと幸いです。",
        );

        $this->service->classify($email);

        $fresh = $email->fresh();
        $this->assertSame('engineer', $fresh->category);
        $this->assertStringStartsWith(
            'body_engineer_keyword:',
            $fresh->extracted_data['classification_reason'] ?? '',
        );
    }

    /** 報告ケース #2 */
    public function test_classifies_as_engineer_for_engineer_self_introduction_with_request(): void
    {
        $email = $this->makeEmail(
            '【Java（Android）、Kotlinエンジニア！！　基本設計～対応可能　バージョンアップ経験 Android SDK】',
            "ご担当者様\n\nお世話になっております。\n株式会社フライハテック営業担当です。\n\nこの度はJava（Android）、Kotlinエンジニアのご紹介となります。\n案件ございましたらご紹介頂けますと大変幸いです。",
        );

        $this->service->classify($email);

        $this->assertSame('engineer', $email->fresh()->category);
    }

    public function test_classifies_as_engineer_when_body_has_youin_jouhou_alone(): void
    {
        $email = $this->makeEmail(
            'ご相談',
            '添付の要員情報をご確認ください。',
        );

        $this->service->classify($email);

        $this->assertSame('engineer', $email->fresh()->category);
    }

    public function test_classifies_as_engineer_when_body_has_engineer_no_goshoukai(): void
    {
        $email = $this->makeEmail(
            'PHPエンジニア',
            'お世話になっております。PHPエンジニアのご紹介です。よろしくお願いします。',
        );

        $this->service->classify($email);

        $this->assertSame('engineer', $email->fresh()->category);
    }

    // ─── 営業挨拶／会社紹介メールは other に分類 ───

    /** 報告ケース 2026-05-23: 企業向け問い合わせフォーム経由の B2B 営業挨拶メール */
    public function test_classifies_as_other_when_subject_has_kigyou_toiawase(): void
    {
        $email = $this->makeEmail(
            'Re: 企業向け問い合わせに投稿がありました。',
            "お世話になっております。\nエンジニアが在籍しており、貴社のご要望に応じてエンジニアのご紹介が可能です。\nご挨拶もかねて会社紹介に伺いたく、お打ち合わせ方法についてご返信ください。",
        );

        $this->service->classify($email);

        $fresh = $email->fresh();
        $this->assertSame('other', $fresh->category);
        $this->assertStringStartsWith(
            'subject_other_keyword:',
            $fresh->extracted_data['classification_reason'] ?? '',
        );
    }

    public function test_classifies_as_other_when_body_has_goaisatsu_mokanete(): void
    {
        $email = $this->makeEmail(
            'ご連絡',
            'お世話になっております。ご挨拶もかねて、貴社にお伺いさせていただきたく存じます。',
        );

        $this->service->classify($email);

        $this->assertSame('other', $email->fresh()->category);
    }

    public function test_classifies_as_other_overrides_engineer_body_keyword(): void
    {
        // 「エンジニアのご紹介」と「ご挨拶もかねて」が同時に含まれる場合、
        // 営業挨拶ルールが優先されて other に分類される
        $email = $this->makeEmail(
            'ご挨拶',
            "ご挨拶もかねて会社紹介に伺いたく存じます。\n弊社にもエンジニアのご紹介が可能です。",
        );

        $this->service->classify($email);

        $this->assertSame('other', $email->fresh()->category);
    }
}
