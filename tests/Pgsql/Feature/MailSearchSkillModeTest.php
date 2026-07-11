<?php

namespace Tests\Pgsql\Feature;

use App\Models\Email;
use App\Models\EngineerMailSource;
use Tests\Pgsql\PgsqlTestCase;

/**
 * /mail-search のスキル複数語の結合方式（skill_mode=or|and）を実 PostgreSQL で検証する。
 * skills(jsonb) への ::text ILIKE を使うため pgsql 必須。
 *  - or(既定): いずれかの語を含む行がヒット
 *  - and: すべての語を含む行のみヒット
 */
class MailSearchSkillModeTest extends PgsqlTestCase
{
    private function makeEms(array $skills): int
    {
        $email = Email::factory()->create(['tenant_id' => $this->authUser->tenant_id]);
        return EngineerMailSource::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'email_id'  => $email->id,
            'skills'    => $skills,
            'status'    => 'new',
        ])->id;
    }

    public function test_or_はいずれかの語を含む行を返す(): void
    {
        $this->actingAsUser();
        $both = $this->makeEms(['Java', 'Python']);
        $javaOnly = $this->makeEms(['Java', 'AWS']);
        $neither = $this->makeEms(['Go', 'Rust']);

        $res = $this->getJson('/api/v1/mail-search?kind=engineer&skill=' . urlencode('Java Python') . '&skill_mode=or');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($both, $ids);
        $this->assertContains($javaOnly, $ids, 'OR は片方一致も含む');
        $this->assertNotContains($neither, $ids);
    }

    public function test_and_はすべての語を含む行のみ返す(): void
    {
        $this->actingAsUser();
        $both = $this->makeEms(['Java', 'Python']);
        $javaOnly = $this->makeEms(['Java', 'AWS']);
        $pythonOnly = $this->makeEms(['Python', 'Django']);

        $res = $this->getJson('/api/v1/mail-search?kind=engineer&skill=' . urlencode('Java Python') . '&skill_mode=and');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($both, $ids, 'AND は両方一致のみ');
        $this->assertNotContains($javaOnly, $ids);
        $this->assertNotContains($pythonOnly, $ids);
    }

    public function test_skill_mode_未指定は_or_扱い(): void
    {
        $this->actingAsUser();
        $javaOnly = $this->makeEms(['Java']);

        $res = $this->getJson('/api/v1/mail-search?kind=engineer&skill=' . urlencode('Java Python'));
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($javaOnly, $ids, '既定は OR なので片方一致も返る');
    }

    public function test_java_は_javascript_を語境界で拾わない(): void
    {
        $this->actingAsUser();
        $java = $this->makeEms(['Java', 'Spring']);
        $jsOnly = $this->makeEms(['JavaScript', 'React']);
        $coreJava = $this->makeEms(['Core Java']);   // 語境界内なら拾う

        $res = $this->getJson('/api/v1/mail-search?kind=engineer&skill=' . urlencode('Java'));
        $res->assertOk();
        $rows = collect($res->json('data'));
        $ids = $rows->pluck('id')->all();

        $this->assertContains($java, $ids, 'Java は拾う');
        $this->assertContains($coreJava, $ids, '"Core Java" は語境界内なので拾う');
        $this->assertNotContains($jsOnly, $ids, 'JavaScript のみの行は拾わない');

        // ハイライト(matched_skills)にも JavaScript を含めない
        $jsRow = $rows->firstWhere('id', $java);
        $this->assertNotContains('JavaScript', $jsRow['matched_skills'] ?? []);
    }

    public function test_C_は_Cpp_や_Csharp_を拾わない(): void
    {
        $this->actingAsUser();
        $cLang = $this->makeEms(['C', 'Embedded']);
        $cpp = $this->makeEms(['C++', 'STL']);
        $csharp = $this->makeEms(['C#', '.NET']);

        $res = $this->getJson('/api/v1/mail-search?kind=engineer&skill=' . urlencode('C'));
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($cLang, $ids, 'C 言語は拾う');
        $this->assertNotContains($cpp, $ids, 'C++ は別スキルなので拾わない');
        $this->assertNotContains($csharp, $ids, 'C# は別スキルなので拾わない');
    }

    public function test_本文一致で拾える_skillsに無くても(): void
    {
        // 辞書に語を足した直後（再抽出前）でも、メール本文に含まれていれば拾えること。
        $this->actingAsUser();
        $email = Email::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'subject'   => 'RPA開発の要員募集',
            'body_text' => 'RPA（UiPath）の実務経験者を探しています。',
        ]);
        $bodyOnly = EngineerMailSource::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'email_id'  => $email->id,
            'skills'    => ['Java'],   // skills には RPA 無し
            'score'     => 50, 'status' => 'new',
        ])->id;

        $res = $this->getJson('/api/v1/mail-search?kind=engineer&skill=RPA');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($bodyOnly, $ids, 'skills に無くても本文一致で拾う');
    }

    public function test_スコア0点は表示しない(): void
    {
        $this->actingAsUser();
        $scored = EngineerMailSource::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'email_id'  => Email::factory()->create(['tenant_id' => $this->authUser->tenant_id])->id,
            'skills'    => ['Java'], 'score' => 50, 'status' => 'new',
        ])->id;
        $zero = EngineerMailSource::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'email_id'  => Email::factory()->create(['tenant_id' => $this->authUser->tenant_id])->id,
            'skills'    => ['Java'], 'score' => 0, 'status' => 'excluded',
        ])->id;

        $res = $this->getJson('/api/v1/mail-search?kind=engineer&skill=Java');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($scored, $ids);
        $this->assertNotContains($zero, $ids, 'score=0 は表示しない');
    }

    public function test_日本語スキルが検索できる(): void
    {
        // skills は json 型で非ASCIIが \uXXXX 保存される。::jsonb::text 照合の回帰。
        $this->actingAsUser();
        $help = $this->makeEms(['ヘルプデスク', '運用保守']);
        $other = $this->makeEms(['Java', 'AWS']);

        $res = $this->getJson('/api/v1/mail-search?kind=engineer&skill=' . urlencode('ヘルプデスク'));
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($help, $ids, '日本語スキル「ヘルプデスク」で拾える');
        $this->assertNotContains($other, $ids);
    }

    public function test_記号入りスキルは完全な語として拾う(): void
    {
        $this->actingAsUser();
        $cpp = $this->makeEms(['C++', 'STL']);
        $cLang = $this->makeEms(['C']);

        // "C++" 検索は C++ を拾い、C 言語だけの行は拾わない
        $res = $this->getJson('/api/v1/mail-search?kind=engineer&skill=' . urlencode('C++'));
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($cpp, $ids, 'C++ は C++ を拾う');
        $this->assertNotContains($cLang, $ids, 'C++ 検索で C 言語だけの行は拾わない');
    }
}
