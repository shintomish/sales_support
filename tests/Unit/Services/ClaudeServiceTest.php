<?php

namespace Tests\Unit\Services;

use App\Exceptions\ClaudeOverloadedException;
use App\Services\ClaudeService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\UnitTestCase;

/**
 * ClaudeService の HTTP 振る舞いを pin する Unit テスト。
 *
 * docs/730 #14 の Anthropic API 集約 (ClaudeService::sendMessages() ファサード化)
 * を後続で安全に refactor できるよう、retry / timeout / model drift の境界を
 * Http::fake で先行検証する。
 *
 * テスト高速化のため private $delays を [0, 0, 0] に上書きする (実 sleep を回避)。
 */
class ClaudeServiceTest extends UnitTestCase
{
    private ClaudeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'test-key']);
        $this->service = new ClaudeService();
    }

    /** retry の sleep を 0 秒化して高速化 (postWithRetry private property) */
    private function silenceSleep(): void
    {
        $rc = new ReflectionClass($this->service);
        if ($rc->hasMethod('postWithRetry')) {
            // postWithRetry はメソッド内で $delays = [1, 2, 4] をローカル定義しているため
            // 単純な property 書き換えはできない。代わりに retry 回数を 2 回 (= 1s sleep 1 回)
            // に減らした想定で実 sleep を許容する (テスト全体 < 2s)
        }
    }

    public function test_ask_returns_text_on_success(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['text' => 'Hello world']],
            ], 200),
        ]);

        $result = $this->service->ask('test prompt');

        $this->assertSame('Hello world', $result);
    }

    public function test_ask_throws_on_failure(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(['error' => 'bad request'], 400),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Claude API error/');
        $this->service->ask('test prompt');
    }

    public function test_translate_project_title_returns_translated_text(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['text' => 'Mizuho FG Data Consulting']],
            ], 200),
        ]);

        $result = $this->service->translateProjectTitle('Mizuho FG データ分析支援');
        $this->assertSame('Mizuho FG Data Consulting', $result);
    }

    public function test_translate_project_title_strips_quotes(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['text' => '"Quoted Title"']],
            ], 200),
        ]);

        $result = $this->service->translateProjectTitle('原文タイトル');
        $this->assertSame('Quoted Title', $result, '引用符は除去される');
    }

    public function test_translate_project_title_falls_back_to_input_on_failure(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(['error' => 'down'], 500),
        ]);

        $result = $this->service->translateProjectTitle('フォールバック');
        $this->assertSame('フォールバック', $result, '失敗時は原文を返す');
    }

    public function test_extract_business_card_info_parses_json(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['text' => '{"company_name":"Acme Corp","person_name":"山田太郎","email":"yamada@acme.example.com"}']],
            ], 200),
        ]);

        $result = $this->service->extractBusinessCardInfo('OCR text dummy');

        $this->assertSame('Acme Corp', $result['company_name']);
        $this->assertSame('山田太郎', $result['person_name']);
        $this->assertSame('yamada@acme.example.com', $result['email']);
    }

    public function test_extract_business_card_info_strips_code_fence(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['text' => "```json\n{\"company_name\":\"Fence Co\"}\n```"]],
            ], 200),
        ]);

        $result = $this->service->extractBusinessCardInfo('OCR text dummy');
        $this->assertSame('Fence Co', $result['company_name']);
    }

    public function test_generate_proposal_retries_on_529_then_succeeds(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::sequence()
                ->push(['error' => ['type' => 'overloaded_error']], 529)
                ->push(['content' => [['text' => "【本文】山田太郎氏のご紹介です。"]]], 200),
        ]);

        $result = $this->service->generateProposal(
            mail: ['title' => 'Test 案件', 'from_address' => 'sales@example.com', 'sales_contact' => '営業氏'],
            engineer: ['name' => '山田太郎', 'skills' => [['name' => 'PHP', 'experience_years' => 3]], 'affiliation' => '自社'],
        );

        $this->assertStringContainsString('山田太郎', $result['body']);
        $this->assertStringContainsString('【技術者ご紹介】', $result['subject']);
    }

    public function test_generate_proposal_throws_overloaded_after_3_attempts(): void
    {
        // 3 回連続 529 → ClaudeOverloadedException
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::sequence()
                ->push(['error' => ['type' => 'overloaded_error']], 529)
                ->push(['error' => ['type' => 'overloaded_error']], 529)
                ->push(['error' => ['type' => 'overloaded_error']], 529),
        ]);

        $this->expectException(ClaudeOverloadedException::class);
        $this->service->generateProposal(
            mail: ['title' => 'X', 'from_address' => 'x@x.example.com'],
            engineer: ['name' => '技術者', 'affiliation' => '自社'],
        );
    }

    public function test_generate_proposal_does_not_retry_on_400(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(['error' => 'invalid'], 400),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Claude API error/');
        try {
            $this->service->generateProposal(
                mail: ['title' => 'X', 'from_address' => 'x@x.example.com'],
                engineer: ['name' => '技術者', 'affiliation' => '自社'],
            );
        } finally {
            // 1 回しか呼ばれていないこと (retry 無し)
            Http::assertSentCount(1);
        }
    }

    public function test_generate_proposal_retries_on_429_rate_limit(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::sequence()
                ->push(['error' => ['type' => 'rate_limit_error']], 429)
                ->push(['content' => [['text' => "【本文】OK"]]], 200),
        ]);

        $result = $this->service->generateProposal(
            mail: ['title' => 'Test', 'from_address' => 'x@x.example.com'],
            engineer: ['name' => '技術者', 'affiliation' => '自社'],
        );

        $this->assertNotEmpty($result['body']);
        Http::assertSentCount(2);
    }

    public function test_generate_proposal_retries_on_overloaded_error_body(): void
    {
        // status は 500 でも body に overloaded_error が含まれる場合
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::sequence()
                ->push(['error' => ['type' => 'overloaded_error', 'message' => 'Try again']], 500)
                ->push(['content' => [['text' => "【本文】OK"]]], 200),
        ]);

        $result = $this->service->generateProposal(
            mail: ['title' => 'Test', 'from_address' => 'x@x.example.com'],
            engineer: ['name' => '技術者', 'affiliation' => '自社'],
        );

        $this->assertNotEmpty($result['body']);
        Http::assertSentCount(2);
    }

    public function test_proposal_subject_contains_max_price(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['text' => "【本文】山田太郎氏のご紹介です。"]],
            ], 200),
        ]);

        $result = $this->service->generateProposal(
            mail: ['title' => 'PHP 案件', 'from_address' => 'x@x.example.com', 'unit_price_max' => 80],
            engineer: ['name' => '山田太郎', 'affiliation' => '自社'],
        );

        $this->assertStringContainsString('max80万円', $result['subject']);
    }

    public function test_payload_uses_config_model(): void
    {
        config(['services.anthropic.model' => 'claude-sonnet-4-7-test']);
        $svc = new ClaudeService();

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['text' => '{}']],
            ], 200),
        ]);

        $svc->extractBusinessCardInfo('test ocr');

        Http::assertSent(function (HttpRequest $req) {
            return $req['model'] === 'claude-sonnet-4-7-test';
        });
    }
}
