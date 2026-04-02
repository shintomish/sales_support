<?php

use App\Jobs\SyncEmailsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── メール自動同期（15分毎）────────────────────────────────
Schedule::call(function () {
    (new SyncEmailsJob())->handle(app(\App\Services\GmailService::class));
})
    ->everyFifteenMinutes()
    ->name('sync-emails')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Schedule] SyncEmailsJob 失敗');
    });

// ── メール自動分類（15分毎）────────────────────────────────
// sync-emails の後に同一スケジュールで実行（チェーン）
Schedule::call(function () {
    $count = app(\App\Services\EmailClassificationService::class)->classifyPending();
    if ($count > 0) {
        \Illuminate\Support\Facades\Log::info("[Schedule] メール分類完了: {$count}件");
    }
})
    ->everyFifteenMinutes()
    ->name('classify-emails')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Schedule] classify-emails 失敗');
    });

// ── メール情報抽出（30分毎・Claude API）────────────────────
Schedule::call(function () {
    $count = app(\App\Services\EmailExtractionService::class)->extractPending();
    if ($count > 0) {
        \Illuminate\Support\Facades\Log::info("[Schedule] メール抽出完了: {$count}件");
    }
})
    ->everyThirtyMinutes()
    ->name('extract-emails')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Schedule] extract-emails 失敗');
    });
