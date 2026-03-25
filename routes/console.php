<?php

use App\Jobs\SyncEmailsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── メール自動同期（15分毎）────────────────────────────────
// 全テナントのGmailを自動取得してemailsテーブルに保存する
Schedule::job(new SyncEmailsJob())
    ->everyFifteenMinutes()
    ->withoutOverlapping()   // 前のJobが終わっていなければスキップ
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Schedule] SyncEmailsJob 失敗');
    });
