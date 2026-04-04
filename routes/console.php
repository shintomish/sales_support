<?php

use App\Jobs\SyncEmailsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── メール自動同期（15分毎）
Schedule::call(function () {
    (new SyncEmailsJob())->handle(app(\App\Services\GmailService::class));
})
    ->everyFifteenMinutes()
    ->name('sync-emails')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Schedule] SyncEmailsJob 失敗');
    });

// ── メール自動分類（15分毎）
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

// ── 案件メールスコアリング（15分毎）
Schedule::call(function () {
    $count = app(\App\Services\ProjectMailScoringService::class)->scorePending();
    if ($count > 0) {
        \Illuminate\Support\Facades\Log::info("[Schedule] 案件スコアリング完了: {$count}件");
    }
})
    ->everyFifteenMinutes()
    ->name('score-project-mails')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Schedule] score-project-mails 失敗');
    });
