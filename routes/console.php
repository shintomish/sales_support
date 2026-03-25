<?php

use App\Jobs\SyncEmailsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── メール自動同期（15分毎）────────────────────────────────
// キューを使わず同期実行（dispatchSync）
Schedule::call(function () {
    (new SyncEmailsJob())->handle(app(\App\Services\GmailService::class));
})
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('sync-emails')
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Schedule] SyncEmailsJob 失敗');
    });
