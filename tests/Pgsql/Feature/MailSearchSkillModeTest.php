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
}
