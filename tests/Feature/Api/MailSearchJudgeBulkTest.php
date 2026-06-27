<?php

namespace Tests\Feature\Api;

use App\Models\AiMatchJudgment;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * /mail-search 一括AI判定(judge-bulk)のテスト。
 *  - 並列判定→キャッシュ保存→2回目はキャッシュ参照(AI呼び出し0)
 *  - BULK_AI_CAP(30)を超える候補は1回で30件まで
 *
 * Claude API は Http::fake で固定応答に差し替える(全 pool リクエストに同一応答)。
 */
class MailSearchJudgeBulkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.haiku_model' => 'test-haiku']);
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['text' => '{"verdict":"◯","reason":"Java一致"}']],
            ], 200),
        ]);
    }

    private function items(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['type' => 'engineer_mail', 'label' => '技術者メール', 'id' => $i, 'title' => "技術者{$i}", 'skills' => 'Java'];
        }
        return $out;
    }

    public function test_一括判定して2回目はキャッシュ参照になる(): void
    {
        $this->actingAsUser();

        $res = $this->postJson('/api/v1/mail-search/judge-bulk', [
            'query' => 'Javaできる人',
            'items' => $this->items(3),
        ]);
        $res->assertOk()
            ->assertJsonPath('ai_calls', 3)
            ->assertJsonPath('data.0.verdict', '◯')
            ->assertJsonPath('data.0.cached', false);

        $this->assertSame(3, AiMatchJudgment::count());

        // 2回目: 同条件・同候補 → AI呼び出し0・cached=true
        $res2 = $this->postJson('/api/v1/mail-search/judge-bulk', [
            'query' => 'Javaできる人',
            'items' => $this->items(3),
        ]);
        $res2->assertOk()
            ->assertJsonPath('ai_calls', 0)
            ->assertJsonPath('data.0.cached', true);

        $this->assertSame(3, AiMatchJudgment::count());
    }

    public function test_検索意図が違えば別キャッシュになる(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/v1/mail-search/judge-bulk', ['query' => 'Java', 'items' => $this->items(2)])->assertOk();
        $this->postJson('/api/v1/mail-search/judge-bulk', ['query' => 'Python', 'items' => $this->items(2)])->assertOk();
        // 同候補でも query が違えば別行
        $this->assertSame(4, AiMatchJudgment::count());
    }

    public function test_TTL超過の判定は無視され再判定される(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/v1/mail-search/judge-bulk', ['query' => 'Java', 'items' => $this->items(1)])
            ->assertOk()->assertJsonPath('ai_calls', 1);

        // 15日後（TTL=14日 超過）→ キャッシュ無視で再判定
        $this->travel(15)->days();
        $res = $this->postJson('/api/v1/mail-search/judge-bulk', ['query' => 'Java', 'items' => $this->items(1)]);
        $res->assertOk()->assertJsonPath('ai_calls', 1)->assertJsonPath('data.0.cached', false);
        $this->assertSame(1, AiMatchJudgment::count()); // 同キーは更新（増えない）
        $this->travelBack();
    }

    public function test_新規AI判定は1回あたり最大30件(): void
    {
        $this->actingAsUser();
        $res = $this->postJson('/api/v1/mail-search/judge-bulk', [
            'query' => 'Java',
            'items' => $this->items(40),
        ]);
        $res->assertOk()->assertJsonPath('ai_calls', 30);
        $this->assertSame(30, AiMatchJudgment::count());
    }
}
