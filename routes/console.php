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

// ── メール自動同期（10分毎・:00,:10,…）— KAGOYA POP3 直接受信
// KAGOYA_POP3_HOST 未設定の環境では silent skip。
// EXAMINE INBOX (read-only) で本番に影響しないため、env がある環境は全て取込可能。
// 2026-06-05: 取込→分類+採点 のパイプライン整列のため 10分毎(:X0)に固定。
// その 5分後(:X5)に classify/score-* を回し、取込済みメールを確実に拾わせる。
Schedule::call(function () {
    if (!config('services.kagoya_pop3.host')) {
        return;
    }
    $count = app(\App\Services\KagoyaMailService::class)->syncEmails();
    if ($count > 0) {
        Log::info("[Schedule] KagoyaPOP3 同期完了: {$count}件");
    }
})
    ->everyTenMinutes()
    ->name('sync-kagoya-pop3')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[Schedule] KagoyaPOP3 同期失敗');
    });

// ── メール自動分類（10分毎・:05,:15,…＝取込の5分後）
// 2026-06-05: 一覧反映ラグ短縮のため 15分→10分に短縮。取込(:X0)の 5分後(:X5)に回し、
// 取込直後のメールを確実に分類対象へ含める。
// 分類はキーワード/正規表現のみ（Claude API 不使用・1 SQL 一括 UPDATE）なので頻度増の負荷は軽微。
// 採点(score-*)の前提条件なので採点と同一 schedule:run(:X5) で先に実行される（定義順）。
Schedule::call(function () {
    $count = app(\App\Services\EmailClassificationService::class)->classifyPending();
    if ($count > 0) {
        \Illuminate\Support\Facades\Log::info("[Schedule] メール分類完了: {$count}件");
    }
})
    ->cron('5-59/10 * * * *')
    ->name('classify-emails')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Schedule] classify-emails 失敗');
    });

// ── 技術者メール新着取込（10分毎・:05,:15,…＝取込の5分後・100件バッチ）
// 2026-06-05: 一覧鮮度のボトルネックは採点(EMS生成)だったため 15分→10分に短縮。取込(:X0)の
// 5分後(:X5)に回し、分類(同一run・定義順で先)→採点 が取込直後のメールを拾う。
// scorePending(100) は withAttachment=false（既定）で Claude API 不使用。増えるのは DB 採点負荷のみ。
// anti-join は index 済で per-run は新着分のみ（連続実行で件数は小さく保たれる）。
Schedule::call(function () {
    $count = app(\App\Services\EngineerMailScoringService::class)->scorePending(100);
    if ($count > 0) {
        Log::info("[Schedule] 技術者メール新着取込完了: {$count}件");
    }
})
    ->cron('5-59/10 * * * *')
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

// ── 案件メールスコアリング（10分毎・:05,:15,…＝取込の5分後）
// 2026-06-05: 一覧鮮度のボトルネックは採点(PMS生成)だったため 15分→10分に短縮。取込(:X0)の
// 5分後(:X5)に回す。案件採点は全て正規表現抽出で Claude API 不使用。増えるのは DB 採点負荷のみ。
Schedule::call(function () {
    $count = app(\App\Services\ProjectMailScoringService::class)->scorePending();
    if ($count > 0) {
        Log::info("[Schedule] 案件スコアリング完了: {$count}件");
    }
})
    ->cron('5-59/10 * * * *')
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

// ── Kagoya 古い outsource@ メールのサーバー削除（毎日 3:30 JST・本番限定）
// outsource@ 宛メールは DB に取込済みなので、14日より古い分を Kagoya サーバーから削除して
// メールボックス肥大/同期負荷を抑える。削除は取込済み(imap-UID)のみ・冪等・UID増分同期に影響なし。
// 本番限定（Kagoya 認証 + ローカルからの本番メール誤操作回避。refresh-domain-bonus と同方針）。
Schedule::command('kagoya:purge-outsource --older-than-days=14 --execute --force --limit=10000')
    ->dailyAt('03:30')
    ->timezone('Asia/Tokyo')
    ->environments(['production'])
    ->name('purge-outsource-kagoya')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('[Schedule] Kagoya outsource@ 削除 完了');
    })
    ->onFailure(function () {
        Log::error('[Schedule] purge-outsource-kagoya 失敗');
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

// ── Kagoya 配送遅延アラート（毎時・本番限定）
// 直近1hの平均配送遅延(到着-送信)が閾値(360分=6h)超なら日次レポート配信先に通知。
// 配送遅延は常態的に ~3.7h あるため、平常では鳴らさず「さらに悪化(6h超)」のみ検知する閾値。
// Kagoya のキュー滞留悪化を早期検知する (project_kagoya_gmail_delivery)。
Schedule::command('emails:check-delivery-delay --threshold=360')
    ->hourly()
    ->timezone('Asia/Tokyo')
    ->environments(['production'])
    ->name('check-delivery-delay')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[Schedule] check-delivery-delay 失敗');
    });

// ── SES Inbound 経路 死活監視（毎時・本番限定）
// SES→S3→Lambda→CF→API のハートビートが古い(=経路ダウン疑い)のに直近2hで取込が続いて
// いる(メールは IMAP で流れている)場合に通知。Phase 2(IMAP 低頻度化)で安全網を薄くする前提の
// 「壊れたら気づく」仕組み (project_ses_inbound_blight)。トラフィック自己ゲートで夜間は鳴らない。
Schedule::command('emails:check-ses-health --threshold=120')
    ->hourly()
    ->timezone('Asia/Tokyo')
    ->environments(['production'])
    ->name('check-ses-health')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[Schedule] check-ses-health 失敗');
    });

// ── 月別売上集計：前月分を全テナント再集計（毎月1日 1:00 JST・docs/460）
// SES台帳(ses_contracts)を契約期間ベース・月単位粗計上で monthly_sales_details に確定。
// 月初に前月が確定するため、当月 1 日に前月を一括集計する。Auth コンテキスト無し=全テナント横断。
Schedule::call(function () {
    $result = app(\App\Services\MonthlySalesAggregationService::class)->aggregatePreviousMonth();
    Log::info('[Schedule] 月別売上集計 完了', $result);
})
    ->monthlyOn(1, '01:00')
    ->timezone('Asia/Tokyo')
    ->name('aggregate-monthly-sales')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[Schedule] aggregate-monthly-sales 失敗');
    });

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

// ── 朝の日次配信レポート（毎日 8:48 JST）前日の配信・提案実績をメール送信
// 8:30 は 15分ジョブ群（classify / score-*）と同一 schedule:run 内で逐次実行され、レポートの
// 番が回るのが ~8:34 になり朝の Session Pooler 飽和に巻き込まれて ECHECKOUTTIMEOUT で落ちて
// いた（2026-05-26）。当初は :40 に逃がしていたが、2026-06-05 に取込を :X0 / classify・score-* を
// :X5 の 10分毎へ再編したため、朝の採点バッチは 8:45 に来る。そのバッチが捌けた後の 8:48 に置き、
// レポートを単独の schedule:run で実行して Pooler 競合を回避する（次の取込 :50 とも非同一run）。
Schedule::command("report:daily-delivery-report")
    ->dailyAt("08:48")
    ->timezone("Asia/Tokyo")
    ->name("daily-delivery-report")
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info("[Schedule] 日次配信レポート 完了");
    })
    ->onFailure(function () {
        Log::error("[Schedule] 日次配信レポート 失敗");
    });
