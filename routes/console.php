<?php

use App\Jobs\SyncEmailsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── メール自動同期（15分毎）— Gmail API
// 2026-05-14: Kagoya IMAP 一本化のため停止（取込重複と Gmail 転送 SPF Softfail 解消）
// 復活させる場合はコメントを外すだけで OK。OAuth トークンと添付 fallback は維持。
// Schedule::call(function () {
//     (new SyncEmailsJob())->handle(app(\App\Services\GmailService::class));
// })
//     ->everyFifteenMinutes()
//     ->name('sync-emails')
//     ->withoutOverlapping()
//     ->onFailure(function () {
//         \Illuminate\Support\Facades\Log::error('[Schedule] SyncEmailsJob 失敗');
//     });

// ── メール自動同期（15分毎）— KAGOYA POP3 直接受信
// production 環境限定: ローカル/dev は KAGOYA_POP3_* env を持たないため
// 起動するたびに DNS 失敗のエラーログが残るのを防ぐ。
Schedule::call(function () {
    $count = app(\App\Services\KagoyaMailService::class)->syncEmails();
    if ($count > 0) {
        Log::info("[Schedule] KagoyaPOP3 同期完了: {$count}件");
    }
})
    ->everyFifteenMinutes()
    ->environments(['production'])
    ->name('sync-kagoya-pop3')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[Schedule] KagoyaPOP3 同期失敗');
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

// ── 技術者メール新着取込（15分毎・100件バッチ）
Schedule::call(function () {
    $count = app(\App\Services\EngineerMailScoringService::class)->scorePending(100);
    if ($count > 0) {
        Log::info("[Schedule] 技術者メール新着取込完了: {$count}件");
    }
})
    ->everyFifteenMinutes()
    ->name('score-engineer-mails')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[Schedule] score-engineer-mails 失敗');
    });

// ── 案件メールスコアリング（15分毎）
Schedule::call(function () {
    $count = app(\App\Services\ProjectMailScoringService::class)->scorePending();
    if ($count > 0) {
        Log::info("[Schedule] 案件スコアリング完了: {$count}件");
    }
})
    ->everyFifteenMinutes()
    ->name('score-project-mails')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[Schedule] score-project-mails 失敗');
    });

// ── メールクリーンアップ（毎日 3:00 JST）
Schedule::command('emails:cleanup')
    ->dailyAt('03:00')
    ->timezone('Asia/Tokyo')
    ->name('cleanup-emails')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('[Schedule] メールクリーンアップ 完了');
    })
    ->onFailure(function () {
        Log::error('[Schedule] メールクリーンアップ 失敗');
    });

// ── 分類済みメールをGmailゴミ箱に移動（毎日 2:00 JST）
// 2026-05-14: Gmail API 取込停止に伴い、ゴミ箱移動も停止
// 復活させる場合はコメントを外すだけで OK
// Schedule::command('gmail:trash-classified')
//     ->dailyAt('02:00')
//     ->timezone('Asia/Tokyo')
//     ->name('trash-classified-emails')
//     ->withoutOverlapping()
//     ->onSuccess(function () {
//         Log::info('[Schedule] 分類済みメールのゴミ箱移動 完了');
//     })
//     ->onFailure(function () {
//         Log::error('[Schedule] 分類済みメールのゴミ箱移動 失敗');
//     });

// ── Vision API キーローテーション（90日毎 / 毎月1日 0:00）
Schedule::command('vision:rotate-key')
    ->monthly()
    ->name('rotate-vision-key')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('[Schedule] Vision API キーローテーション完了');
    })
    ->onFailure(function () {
        Log::error('[Schedule] Vision API キーローテーション失敗');
    });

// ── 朝の日次レポート配信（毎日 8:30 JST）
Schedule::command("report:daily-sales")
    ->dailyAt("08:30")
    ->timezone("Asia/Tokyo")
    ->name("daily-sales-report")
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info("[Schedule] 日次レポート配信 完了");
    })
    ->onFailure(function () {
        Log::error("[Schedule] 日次レポート配信 失敗");
    });
