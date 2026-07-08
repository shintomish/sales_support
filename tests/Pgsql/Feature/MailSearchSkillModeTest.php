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

    public function test_記号入りスキルは部分一致にフォールバック(): void
    {
        $this->actingAsUser();
        $cpp = $this->makeEms(['C++', 'STL']);

        // "C++" は語境界が定義しづらいので ILIKE フォールバックで拾える
        $res = $this->getJson('/api/v1/mail-search?kind=engineer&skill=' . urlencode('C++'));
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($cpp, $ids);
    }
}
