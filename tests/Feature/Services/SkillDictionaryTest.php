<?php

namespace Tests\Feature\Services;

use App\Services\SkillDictionary;
use Tests\TestCase;

/**
 * スキル同義語辞書（名寄せ）のテスト。
 *  - normalize(): 大小・全角英数・空白の揺れを吸収
 *  - canonical(): 同義語が同一グループキーに寄る / 未知語は自身
 *  - expand(): 表記揺れを全て返す（ILIKE OR 用）/ 未知語は自身のみ（取りこぼし防止）
 *
 * 辞書データは migration の seed（skill_aliases）に依存。
 */
class SkillDictionaryTest extends TestCase
{
    private SkillDictionary $dict;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dict = app(SkillDictionary::class);
    }

    public function test_normalize_は大小と全角英数を吸収する(): void
    {
        $this->assertSame('java', $this->dict->normalize('  Ｊａｖａ '));
        $this->assertSame('sql server', $this->dict->normalize('SQL   Server'));
    }

    public function test_同義語は同一の正規名グループに寄る(): void
    {
        $canon = $this->dict->canonical('Java');
        $this->assertSame($canon, $this->dict->canonical('JAVA'));
        $this->assertSame($canon, $this->dict->canonical('ジャバ'));

        $this->assertSame($this->dict->canonical('React'), $this->dict->canonical('React.js'));
        $this->assertSame($this->dict->canonical('社内SE'), $this->dict->canonical('情シス'));
    }

    public function test_別物は別グループのまま(): void
    {
        // Java と JavaScript は同義にしない
        $this->assertNotSame($this->dict->canonical('Java'), $this->dict->canonical('JavaScript'));
    }

    public function test_未知語は自身のみを返す(): void
    {
        $this->assertSame('cobol', $this->dict->canonical('COBOL'));
        $this->assertSame(['COBOL'], $this->dict->expand('COBOL'));
    }

    public function test_expand_は全表記揺れを含む(): void
    {
        $forms = array_map(fn($s) => mb_strtolower($s), $this->dict->expand('社内SE'));
        $this->assertContains('情報システム', $forms);
        $this->assertContains('情シス', $forms);
        $this->assertContains('社内se', $forms);
    }
}
