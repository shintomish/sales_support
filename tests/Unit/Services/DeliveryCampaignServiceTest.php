<?php

namespace Tests\Unit\Services;

use App\Mail\DeliveryMail;
use App\Models\DeliveryAddress;
use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use App\Models\EngineerMailSource;
use App\Models\Tenant;
use App\Services\DeliveryCampaignService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * DeliveryCampaignService の Unit テスト。
 *
 * createCampaign は集計とフィールド設定、sendCampaign は SES 送信を Mockery で
 * 差し替えて成功・失敗・部分失敗の集計挙動を検証する。
 */
class DeliveryCampaignServiceTest extends TestCase
{
    private DeliveryCampaignService $service;

    /** 元の MAIL_DELIVERY_TEST_TO を保存（テスト終了時に復元） */
    private string|false $originalTestTo;

    protected function setUp(): void
    {
        parent::setUp();

        // .env の MAIL_DELIVERY_TEST_TO が設定されていると全宛先がそこへリダイレクトされる。
        // 個別テストでこの env を意図的に設定するケースを除き、テスト中は無効化する。
        $this->originalTestTo = getenv('MAIL_DELIVERY_TEST_TO');
        putenv('MAIL_DELIVERY_TEST_TO');
        unset($_ENV['MAIL_DELIVERY_TEST_TO'], $_SERVER['MAIL_DELIVERY_TEST_TO']);

        $this->actingAsUser();
        $this->service = new DeliveryCampaignService(
            tenantId:   $this->authUser->tenant_id,
            userId:     $this->authUser->id,
            senderName: '送信太郎',
        );
    }

    protected function tearDown(): void
    {
        if ($this->originalTestTo === false) {
            putenv('MAIL_DELIVERY_TEST_TO');
            unset($_ENV['MAIL_DELIVERY_TEST_TO'], $_SERVER['MAIL_DELIVERY_TEST_TO']);
        } else {
            putenv('MAIL_DELIVERY_TEST_TO=' . $this->originalTestTo);
            $_ENV['MAIL_DELIVERY_TEST_TO']    = $this->originalTestTo;
            $_SERVER['MAIL_DELIVERY_TEST_TO'] = $this->originalTestTo;
        }

        Mockery::close();
        parent::tearDown();
    }

    /** 自テナントの DeliveryAddress を作るヘルパ */
    private function makeAddress(array $attrs = []): DeliveryAddress
    {
        return DeliveryAddress::create(array_merge([
            'tenant_id' => $this->authUser->tenant_id,
            'email'     => fake()->unique()->safeEmail(),
            'name'      => fake()->name(),
            'is_active' => true,
        ], $attrs));
    }

    private function makeCampaign(array $attrs = []): DeliveryCampaign
    {
        return DeliveryCampaign::factory()->create(array_merge([
            'tenant_id'   => $this->authUser->tenant_id,
            'user_id'     => $this->authUser->id,
            'subject'     => '件名',
            'body'        => '本文',
            'total_count' => 1,
        ], $attrs));
    }

    // ─── createCampaign ───

    public function test_create_campaign_counts_active_addresses_and_sets_defaults(): void
    {
        $this->makeAddress();
        $this->makeAddress();
        $this->makeAddress(['is_active' => false]);

        $campaign = $this->service->createCampaign([
            'project_mail_id' => null,
            'subject'         => 'タイトル',
            'body'            => '本文',
        ]);

        $this->assertSame(2, $campaign->total_count);
        $this->assertSame('delivery', $campaign->send_type);
        $this->assertSame(0, $campaign->success_count);
        $this->assertSame(0, $campaign->failed_count);
        $this->assertNotNull($campaign->sent_at);
        $this->assertSame($this->authUser->tenant_id, $campaign->tenant_id);
        $this->assertSame($this->authUser->id, $campaign->user_id);
        $this->assertSame('タイトル', $campaign->subject);
        $this->assertSame('本文', $campaign->body);
    }

    public function test_create_campaign_prefers_engineer_mail_source_over_project_mail(): void
    {
        $ems = EngineerMailSource::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
        ]);

        $campaign = $this->service->createCampaign([
            'project_mail_id'         => 999,
            'engineer_mail_source_id' => $ems->id,
            'subject'                 => 'X',
            'body'                    => 'Y',
        ]);

        $this->assertNull($campaign->project_mail_id);
        $this->assertSame($ems->id, $campaign->engineer_mail_source_id);
    }

    public function test_create_campaign_excludes_other_tenant_addresses_from_total_count(): void
    {
        $this->makeAddress();

        $otherTenant = Tenant::factory()->create();
        (new DeliveryAddress)->forceFill([
            'tenant_id'         => $otherTenant->id,
            'email'             => 'other@example.com',
            'name'              => '別テナント',
            'is_active'         => true,
            'unsubscribe_token' => 'tok-other',
        ])->save();

        $campaign = $this->service->createCampaign([
            'subject' => 'X',
            'body'    => 'Y',
        ]);

        $this->assertSame(1, $campaign->total_count);
    }

    public function test_create_campaign_accepts_null_project_mail_id(): void
    {
        $campaign = $this->service->createCampaign([
            'subject' => 'X',
            'body'    => 'Y',
        ]);

        $this->assertNull($campaign->project_mail_id);
        $this->assertNull($campaign->engineer_mail_source_id);
    }

    // ─── sendCampaign ───

    public function test_send_campaign_marks_all_sent_on_success(): void
    {
        Mail::fake();
        $this->makeAddress(['email' => 'a@example.com', 'name' => 'A太郎']);
        $this->makeAddress(['email' => 'b@example.com', 'name' => 'B次郎']);
        $campaign = $this->makeCampaign(['total_count' => 2]);

        $this->service->sendCampaign($campaign);

        Mail::assertSent(DeliveryMail::class, 2);
        Mail::assertSent(DeliveryMail::class, fn ($m) => $m->hasTo('a@example.com'));
        Mail::assertSent(DeliveryMail::class, fn ($m) => $m->hasTo('b@example.com'));

        $campaign->refresh();
        $this->assertSame(2, $campaign->success_count);
        $this->assertSame(0, $campaign->failed_count);

        $this->assertSame(2, DeliverySendHistory::where('campaign_id', $campaign->id)
            ->where('status', 'sent')->count());
    }

    public function test_send_campaign_records_failure_when_send_throws(): void
    {
        $this->makeAddress(['email' => 'fail@example.com', 'name' => '失敗子']);
        $campaign = $this->makeCampaign();

        Mail::shouldReceive('to')
            ->once()
            ->andReturnUsing(function () {
                $m = Mockery::mock();
                $m->shouldReceive('send')->once()->andThrow(new \RuntimeException('SES quota exceeded'));
                return $m;
            });

        $this->service->sendCampaign($campaign);

        $campaign->refresh();
        $this->assertSame(0, $campaign->success_count);
        $this->assertSame(1, $campaign->failed_count);

        $hist = DeliverySendHistory::where('campaign_id', $campaign->id)->firstOrFail();
        $this->assertSame('failed', $hist->status);
        $this->assertSame('SES quota exceeded', $hist->error_message);
        $this->assertSame('fail@example.com', $hist->email);
    }

    public function test_send_campaign_handles_partial_failure(): void
    {
        $this->makeAddress(['email' => 'ok@example.com']);
        $this->makeAddress(['email' => 'ng@example.com']);
        $campaign = $this->makeCampaign(['total_count' => 2]);

        $callIndex = 0;
        Mail::shouldReceive('to')
            ->twice()
            ->andReturnUsing(function () use (&$callIndex) {
                $m = Mockery::mock();
                if ($callIndex === 0) {
                    $m->shouldReceive('send')->once();
                } else {
                    $m->shouldReceive('send')->once()->andThrow(new \Exception('boom'));
                }
                $callIndex++;
                return $m;
            });

        $this->service->sendCampaign($campaign);

        $campaign->refresh();
        $this->assertSame(1, $campaign->success_count);
        $this->assertSame(1, $campaign->failed_count);

        $this->assertSame(1, DeliverySendHistory::where('campaign_id', $campaign->id)
            ->where('status', 'sent')->count());
        $this->assertSame(1, DeliverySendHistory::where('campaign_id', $campaign->id)
            ->where('status', 'failed')->count());
    }

    public function test_send_campaign_replaces_name_placeholder_and_appends_unsubscribe_link(): void
    {
        Mail::fake();
        $address = $this->makeAddress(['email' => 'jiro@example.com', 'name' => '次郎']);
        $campaign = $this->makeCampaign(['body' => '<%Name%>様、こんにちは。']);

        $this->service->sendCampaign($campaign);

        Mail::assertSent(DeliveryMail::class, function ($m) use ($address) {
            $this->assertStringContainsString('次郎様、こんにちは。', $m->body);
            $this->assertStringNotContainsString('<%Name%>', $m->body);
            $this->assertStringContainsString('/unsubscribe/' . $address->unsubscribe_token, $m->body);
            return $m->hasTo('jiro@example.com');
        });
    }

    public function test_send_campaign_redirects_to_test_address_when_env_is_set(): void
    {
        Mail::fake();
        $this->makeAddress(['email' => 'real@example.com']);
        $campaign = $this->makeCampaign();

        putenv('MAIL_DELIVERY_TEST_TO=devtest@example.com');
        $_ENV['MAIL_DELIVERY_TEST_TO']    = 'devtest@example.com';
        $_SERVER['MAIL_DELIVERY_TEST_TO'] = 'devtest@example.com';

        $this->service->sendCampaign($campaign);

        Mail::assertSent(DeliveryMail::class, fn ($m) => $m->hasTo('devtest@example.com'));
        Mail::assertNotSent(DeliveryMail::class, fn ($m) => $m->hasTo('real@example.com'));
    }

    public function test_send_campaign_skips_inactive_addresses(): void
    {
        Mail::fake();
        $this->makeAddress(['email' => 'on@example.com', 'is_active' => true]);
        $this->makeAddress(['email' => 'off@example.com', 'is_active' => false]);
        $campaign = $this->makeCampaign();

        $this->service->sendCampaign($campaign);

        Mail::assertSent(DeliveryMail::class, 1);
        Mail::assertSent(DeliveryMail::class, fn ($m) => $m->hasTo('on@example.com'));
        Mail::assertNotSent(DeliveryMail::class, fn ($m) => $m->hasTo('off@example.com'));
    }

    public function test_send_campaign_attaches_files_and_cleans_up_temp_dir(): void
    {
        Mail::fake();
        $this->makeAddress(['email' => 'a@example.com']);
        $campaign = $this->makeCampaign();

        $tmpDir = sys_get_temp_dir() . '/del_test_' . uniqid();
        mkdir($tmpDir);
        $file = $tmpDir . '/sample.pdf';
        file_put_contents($file, 'PDF DATA');

        $this->service->sendCampaign($campaign, [$file]);

        Mail::assertSent(DeliveryMail::class, function ($m) use ($file) {
            return $m->attachmentPaths === [$file];
        });

        $this->assertFileDoesNotExist($file);
        $this->assertDirectoryDoesNotExist($tmpDir);
    }

    public function test_send_campaign_clears_sending_cache_on_completion(): void
    {
        Mail::fake();
        $this->makeAddress();
        $campaign = $this->makeCampaign();

        $this->assertFalse(Cache::has("campaign_sending_{$campaign->id}"));

        $this->service->sendCampaign($campaign);

        $this->assertFalse(Cache::has("campaign_sending_{$campaign->id}"));
    }
}
