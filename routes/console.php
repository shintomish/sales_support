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
// KAGOYA_POP3_HOST 未設定の環境では silent skip。
// EXAMINE INBOX (read-only) で本番に影響しないため、env がある環境は全て取込可能。
Schedule::call(function () {
    if (!config('services.kagoya_pop3.host')) {
        return;
    }
    $count = app(\App\Services\KagoyaMailService::class)->syncEmails();
    if ($count > 0) {
        Log::info("[Schedule] KagoyaPOP3 同期完了: {$count}件");
    }
})
    ->everyFifteenMinutes()
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

// ── 技術者メール catch-up（毎時・過去30日・上限50件）
// 通常取込は lookback=1日のため、スコアリングが1日以上停止すると、その間に届いた
// engineer メールが恒久的に EMS 未生成のまま取り残される（2026-06 に5/21〜5/28分 846件が滞留）。
// 広い lookback で滞留分を少しずつ拾い、再発を防ぐ。添付は解析しない（通常取込と同じ false）。
Schedule::call(function () {
    $count = app(\App\Services\EngineerMailScoringService::class)->scorePending(50, false, 30);
    if ($count > 0) {
        Log::info("[Schedule] 技術者メール catch-up: {$count}件");
    }
})
    ->hourly()
    ->name('score-engineer-mails-catchup')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[Schedule] score-engineer-mails-catchup 失敗');
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

// ── 案件メール catch-up（毎時・過去30日・上限50件）
// 技術者側と同様、lookback=1日の取込はスコアリング停止 >1日 で取りこぼす。広い lookback で滞留を拾う。
Schedule::call(function () {
    $count = app(\App\Services\ProjectMailScoringService::class)->scorePending(50, 30);
    if ($count > 0) {
        Log::info("[Schedule] 案件メール catch-up: {$count}件");
    }
})
    ->hourly()
    ->name('score-project-mails-catchup')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[Schedule] score-project-mails-catchup 失敗');
    });

// ── 全件再スコアリング ジョブ処理（毎分・時間ボックス処理。docs #4）
// rescore_jobs の最古の未完了 job を 1 件、~50 秒バジェット内でバッチ処理する。
// フロントは POST /rescore-all で job 登録 → /rescore-status をポーリング。
Schedule::call(function () {
    app(\App\Services\RescoreJobRunner::class)->tick();
})
    ->everyMinute()
    ->name('rescore-jobs-tick')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[Schedule] rescore-jobs-tick 失敗');
    });

// ── ドメイン信頼度補正の蓄積反映（毎日 02:40 JST・本番限定。営業打ち合わせ 2026-05-25 §4.6 ケースC）
// 「全件再スコア」ボタンを営業 UI から外した代替。提案実績の蓄積で経時変動する domain bonus を
// 既存スコアへ軽量反映する（extract は触らず score/status のみ・変化行のみ UPDATE）。
// ローカル Supabase は Disk IO 予算が小さいため本番のみで実行。
Schedule::call(function () {
    $changed = app(\App\Services\ProjectMailScoringService::class)->refreshDomainBonus();
    Log::info("[Schedule] domain bonus 反映完了: {$changed}件更新");
})
    ->dailyAt('02:40')
    ->timezone('Asia/Tokyo')
    ->environments(['production'])
    ->name('refresh-domain-bonus')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[Schedule] refresh-domain-bonus 失敗');
    });

// ── hot テーブル 手動 VACUUM（毎日 2:50 JST、cleanup-emails の直前）
// 2026-05-19: autovacuum 0.05 scale でも emails / engineer_mail_sources の
// Heap Fetches が日中 1000+ 蓄積し score-engineer-mails の cold-start レイテンシが
// 数百ms に達するケースがあるため、深夜帯に必ず VM を更新しておく。
// PostgreSQL の VACUUM は ACCESS SHARE のみ取得し、SELECT/INSERT と並列実行可。
Schedule::call(function () {
    foreach (['emails', 'engineer_mail_sources', 'project_mail_sources'] as $table) {
        // テーブル名はホワイトリスト固定なので SQL インジェクション無し
        \Illuminate\Support\Facades\DB::statement("VACUUM ANALYZE {$table}");
    }
    Log::info('[Schedule] hot tables VACUUM ANALYZE 完了');
})
    ->dailyAt('02:50')
    ->timezone('Asia/Tokyo')
    // 本番限定: ローカル Supabase は Disk IO Budget が小さいため、VACUUM の書込で
    // 24h 予算を消費しすぎる (2026-05-21 警告メール)。autovacuum 0.05 で十分なので skip。
    ->environments(['production'])
    ->name('vacuum-hot-tables')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[Schedule] hot tables VACUUM ANALYZE 失敗');
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

// ── 朝の日次配信レポート（毎日 8:40 JST）前日の配信・提案実績をメール送信
// 8:30 は :30 の 15分ジョブ群（classify / score-* = Claude API）と同一 schedule:run 内で
// 逐次実行され、レポートの番が回るのが ~8:34 になり朝の Session Pooler 飽和に巻き込まれて
// ECHECKOUTTIMEOUT で落ちていた（2026-05-26）。15分境界(:30/:45)を外し、:30 バッチが
// 捌けた後の 8:40 に移動して競合を回避する。
Schedule::command("report:daily-delivery-report")
    ->dailyAt("08:40")
    ->timezone("Asia/Tokyo")
    ->name("daily-delivery-report")
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info("[Schedule] 日次配信レポート 完了");
    })
    ->onFailure(function () {
        Log::error("[Schedule] 日次配信レポート 失敗");
    });
