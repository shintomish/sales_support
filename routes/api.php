<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\BusinessCardController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\GmailOAuthController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\EngineerController;
use App\Http\Controllers\Api\PublicProjectController;
use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\MatchingController;
use App\Http\Controllers\Api\ProjectMailController;
use App\Http\Controllers\Api\EngineerMailController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\EmailBodyTemplateController;
use App\Http\Controllers\Api\DeliveryAddressController;
use App\Http\Controllers\Api\DeliveryCampaignController;
use App\Http\Controllers\Api\HealthController;

// ── 認証不要 ────────────────────────────────────────
Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class);

    Route::post('login', [AuthController::class, 'login']);

    // Gmail OAuth（コールバックのみ認証不要）
    Route::get('/gmail/callback', [GmailOAuthController::class, 'callback']);

});

// ── 認証必須 ────────────────────────────────────────
Route::prefix('v1')->middleware(['supabase.auth'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::get('users', [UserController::class, 'index']);
    Route::get('email-body-templates/me',  [EmailBodyTemplateController::class, 'show']);
    Route::put('email-body-templates/me',  [EmailBodyTemplateController::class, 'upsert']);
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('notifications', [NotificationController::class, 'index']);

    // ★ 業種一覧（customers resourceより前に記載すること）
    Route::get('customers/industries', [CustomerController::class, 'industries']);
    // 各リソースのCRUDエンドポイント（名前にapi.を追加）
    Route::apiResource('customers', CustomerController::class)->names([
        'index' => 'api.customers.index',
        'store' => 'api.customers.store',
        'show' => 'api.customers.show',
        'update' => 'api.customers.update',
        'destroy' => 'api.customers.destroy',
    ]);

    Route::apiResource('contacts', ContactController::class)->names([
        'index' => 'api.contacts.index',
        'store' => 'api.contacts.store',
        'show' => 'api.contacts.show',
        'update' => 'api.contacts.update',
        'destroy' => 'api.contacts.destroy',
    ]);

    // Excel インポート（apiResource の前に必須）
    Route::post('deals/import', [App\Http\Controllers\Api\DealImportController::class, 'store']);
    Route::get('deals/import/logs', [App\Http\Controllers\Api\DealImportController::class, 'logs']);
    Route::get('deals/import/logs/{id}', [App\Http\Controllers\Api\DealImportController::class, 'showLog']);
    Route::get('ses-contracts/summary', [App\Http\Controllers\Api\SesContractController::class, 'summary']);
    Route::get('ses-contracts', [App\Http\Controllers\Api\SesContractController::class, 'index']);
    Route::post('ses-contracts', [App\Http\Controllers\Api\SesContractController::class, 'store']);
    Route::get('ses-contracts/{id}', [App\Http\Controllers\Api\SesContractController::class, 'show']);
    Route::put('ses-contracts/{id}', [App\Http\Controllers\Api\SesContractController::class, 'update']);
    Route::post('ses-contracts/{id}/promote', [App\Http\Controllers\Api\SesContractController::class, 'promote']);
    Route::post('ses-contracts/import', [App\Http\Controllers\Api\SesContractController::class, 'import']);

    Route::apiResource('deals', DealController::class)->names([
        'index' => 'api.deals.index',
        'store' => 'api.deals.store',
        'show' => 'api.deals.show',
        'update' => 'api.deals.update',
        'destroy' => 'api.deals.destroy',
    ]);

    Route::apiResource('activities', ActivityController::class)->names([
        'index' => 'api.activities.index',
        'store' => 'api.activities.store',
        'show' => 'api.activities.show',
        'update' => 'api.activities.update',
        'destroy' => 'api.activities.destroy',
    ]);

    Route::apiResource('tasks', TaskController::class)->names([
        'index' => 'api.tasks.index',
        'store' => 'api.tasks.store',
        'show' => 'api.tasks.show',
        'update' => 'api.tasks.update',
        'destroy' => 'api.tasks.destroy',
    ]);
    Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus']);

    // 名刺OCR
    Route::apiResource('cards', BusinessCardController::class)->names([
        'index' => 'api.cards.index',
        'store' => 'api.cards.store',
        'show' => 'api.cards.show',
        'destroy' => 'api.cards.destroy',
    ])->only(['index', 'store', 'show', 'update','destroy']); // update追加

    // Gmail OAuth
    Route::prefix('gmail')->group(function () {
        Route::get('/redirect',    [GmailOAuthController::class, 'redirect']);
        Route::get('/status',      [GmailOAuthController::class, 'status']);
        Route::delete('/disconnect', [GmailOAuthController::class, 'disconnect']);
    });

    // メール
    Route::prefix('emails')->group(function () {
        Route::get('/',              [EmailController::class, 'index']);
        Route::get('/unread-count',  [EmailController::class, 'unreadCount']);
        Route::post('/sync',         [EmailController::class, 'sync']);
        Route::post('/mark-all-read',[EmailController::class, 'markAllRead']); // 全件既読
        Route::get('/{id}',                                      [EmailController::class, 'show']);
        Route::patch('/{id}/link',                               [EmailController::class, 'link']);
        Route::get('/{id}/attachments/{attachmentId}/download',  [EmailController::class, 'downloadAttachment']);
    });

    // ── 案件メール（スコアリング済み）────────────────────
    Route::prefix('project-mails')->group(function () {
        Route::get('/',              [ProjectMailController::class, 'index']);
        Route::post('/rescore-all',   [ProjectMailController::class, 'rescoreAll']);
        Route::post('/reextract-all', [ProjectMailController::class, 'reextractAll']);
        Route::get('/{id}',          [ProjectMailController::class, 'show']);
        Route::patch('/{id}',        [ProjectMailController::class, 'update']);
        Route::patch('/{id}/status', [ProjectMailController::class, 'updateStatus']);
        Route::post('/{id}/rescore',              [ProjectMailController::class, 'rescore']);
        Route::get('/{id}/matched-engineers',     [ProjectMailController::class, 'matchedEngineers']);
        Route::post('/{id}/generate-proposal',    [ProjectMailController::class, 'generateProposal']);
        Route::post('/{id}/send-proposal',        [ProjectMailController::class, 'sendProposal']);
        Route::post('/{id}/send-bulk',            [ProjectMailController::class, 'sendBulk']);
    });

    // ── 技術者メール（スコアリング済み）──────────────────
    Route::prefix('engineer-mails')->group(function () {
        Route::get('/',              [EngineerMailController::class, 'index']);
        Route::post('/rescore-all',  [EngineerMailController::class, 'rescoreAll']);
        Route::get('/{id}',                           [EngineerMailController::class, 'show']);
        Route::get('/{id}/attachment/{attachmentId}', [EngineerMailController::class, 'downloadAttachment']);
        Route::put('/{id}',                           [EngineerMailController::class, 'update']);
        Route::put('/{id}/status',                    [EngineerMailController::class, 'updateStatus']);
        Route::post('/{id}/register-engineer',        [EngineerMailController::class, 'registerEngineer']);
        Route::get('/{id}/matched-projects',          [EngineerMailController::class, 'matchedProjects']);
        Route::post('/{id}/generate-proposal',        [EngineerMailController::class, 'generateProposal']);
        Route::post('/{id}/send-proposal',            [EngineerMailController::class, 'sendProposal']);
    });

    // ── マッチング機能 ───────────────────────────────────

    // スキルマスタ
    Route::get('matching/skills',  [MatchingController::class, 'skills']);
    Route::post('matching/skills', [MatchingController::class, 'storeSkill']);

    // 技術者 CRUD
    Route::post('engineers/parse-skill-sheet', [EngineerController::class, 'parseSkillSheet']);
    Route::get('engineers',       [EngineerController::class, 'index']);
    Route::post('engineers',      [EngineerController::class, 'store']);
    Route::get('engineers/{id}',  [EngineerController::class, 'show']);
    Route::put('engineers/{id}',  [EngineerController::class, 'update']);
    Route::delete('engineers/{id}', [EngineerController::class, 'destroy']);
    // 技術者へのおすすめ案件
    Route::get('matching/engineers/{id}/projects', [MatchingController::class, 'recommendProjects']);
    // 技術者の応募一覧
    Route::get('engineers/{id}/applications', [ApplicationController::class, 'indexByEngineer']);

    // 公開案件 CRUD
    Route::get('public-projects',        [PublicProjectController::class, 'index']);
    Route::post('public-projects',       [PublicProjectController::class, 'store']);
    Route::get('public-projects/{id}',   [PublicProjectController::class, 'show']);
    Route::put('public-projects/{id}',   [PublicProjectController::class, 'update']);
    Route::delete('public-projects/{id}',[PublicProjectController::class, 'destroy']);
    Route::post('public-projects/{id}/favorite', [PublicProjectController::class, 'toggleFavorite']);
    // 案件へのおすすめ技術者
    Route::get('matching/projects/{id}/engineers', [MatchingController::class, 'recommendEngineers']);
    // 案件への応募一覧
    Route::get('public-projects/{id}/applications', [ApplicationController::class, 'indexByProject']);

    // 応募 CRUD・選考
    Route::post('applications',                       [ApplicationController::class, 'store']);
    Route::get('applications/{id}',                   [ApplicationController::class, 'show']);
    Route::patch('applications/{id}/status',          [ApplicationController::class, 'updateStatus']);
    Route::post('applications/{id}/messages',         [ApplicationController::class, 'sendMessage']);
    Route::post('applications/{id}/messages/read',    [ApplicationController::class, 'readMessages']);

    // マッチングスコア詳細（AI説明付き）
    Route::get('matching/projects/{projectId}/engineers/{engineerId}', [MatchingController::class, 'scoreDetail']);

    // P3: マッチング画面から提案メール生成・送信
    Route::post('matching/projects/{projectId}/engineers/{engineerId}/generate-proposal', [MatchingController::class, 'generateProposal']);
    Route::post('matching/projects/{projectId}/engineers/{engineerId}/send-proposal',     [MatchingController::class, 'sendProposal']);

    // ── 配信先管理 ──────────────────────────────────────
    Route::prefix('delivery-addresses')->group(function () {
        Route::get('/',                [DeliveryAddressController::class, 'index']);
        Route::post('/',               [DeliveryAddressController::class, 'store']);
        Route::post('/import',         [DeliveryAddressController::class, 'import']);
        Route::get('/import-progress', [DeliveryAddressController::class, 'importProgress']);
        Route::patch('/{id}',          [DeliveryAddressController::class, 'update']);
        Route::delete('/{id}',         [DeliveryAddressController::class, 'destroy']);
    });

    // ── 配信キャンペーン（送信履歴を統合）───────────────────
    Route::prefix('delivery-campaigns')->group(function () {
        Route::get('/',     [DeliveryCampaignController::class, 'index']);
        Route::post('/',    [DeliveryCampaignController::class, 'store']);
        Route::get('/{id}', [DeliveryCampaignController::class, 'show']);
    });
});
