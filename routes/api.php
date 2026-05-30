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
use App\Http\Controllers\Api\RequirementMatchResultController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\AdminStatsController;
use App\Http\Controllers\Api\WorkRecordController;
use App\Http\Controllers\Api\BillingSummaryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceIssuerController;
use App\Http\Controllers\Api\RefinitivInvoiceController;
use App\Http\Controllers\Api\ReportRecipientController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\EmailBodyTemplateController;
use App\Http\Controllers\Api\DeliveryAddressController;
use App\Http\Controllers\Api\DeliveryCampaignController;
use App\Http\Controllers\Api\HealthController;

// ── 認証不要 ────────────────────────────────────────
Route::prefix('v1')->group(function () {
    Route::get('health',      HealthController::class);
    Route::get('health/deep', [HealthController::class, 'deep']);

    // /login (Sanctum) は docs/730 §Low #41 で削除。FE は Supabase Auth を直接使う。

    // Gmail OAuth（コールバックのみ認証不要）
    Route::get('/gmail/callback', [GmailOAuthController::class, 'callback']);

});

// ── 認証必須 ────────────────────────────────────────
Route::prefix('v1')->middleware(['supabase.auth'])->group(function () {
    // /logout (Sanctum) も docs/730 §Low #41 で削除済。FE 側 supabase.auth.signOut で完結。
    Route::get('me', [AuthController::class, 'me']);
    Route::get('users',                       [UserController::class, 'index']);
    Route::post('users',                      [UserController::class, 'store']);
    Route::put('users/{id}',                  [UserController::class, 'update']);
    Route::delete('users/{id}',               [UserController::class, 'destroy']);
    Route::post('users/{id}/resend-invite',   [UserController::class, 'resendInvite']);
    Route::get('tenants',                     [TenantController::class, 'index']);
    Route::get('admin/stats',                 [AdminStatsController::class, 'index']);
    Route::get('work-records',                               [WorkRecordController::class, 'indexAll']);
    Route::get('deals/{deal}/work-records',                  [WorkRecordController::class, 'index']);
    Route::put('deals/{deal}/work-records/{yearMonth}',      [WorkRecordController::class, 'upsert']);
    Route::delete('deals/{deal}/work-records/{yearMonth}',   [WorkRecordController::class, 'destroy']);
    Route::get('billing-summaries',             [BillingSummaryController::class, 'index']);
    Route::get('billing-summaries/export.csv',  [BillingSummaryController::class, 'export']);

    Route::get('settings/invoice-issuer', [InvoiceIssuerController::class, 'show']);
    Route::put('settings/invoice-issuer', [InvoiceIssuerController::class, 'update']);
    Route::post('settings/invoice-issuer/logo', [InvoiceIssuerController::class, 'uploadLogo']);
    Route::delete('settings/invoice-issuer/logo', [InvoiceIssuerController::class, 'deleteLogo']);
    Route::post('settings/invoice-issuer/seal/{type}', [InvoiceIssuerController::class, 'uploadSeal'])
        ->whereIn('type', ['round', 'square']);
    Route::delete('settings/invoice-issuer/seal/{type}', [InvoiceIssuerController::class, 'deleteSeal'])
        ->whereIn('type', ['round', 'square']);

    Route::get('settings/report-recipients',                 [ReportRecipientController::class, 'index']);
    Route::post('settings/report-recipients',                [ReportRecipientController::class, 'store']);
    Route::put('settings/report-recipients/{recipient}',     [ReportRecipientController::class, 'update']);
    Route::delete('settings/report-recipients/{recipient}',  [ReportRecipientController::class, 'destroy']);

    // 社内バグ・要望フィードバック
    Route::post('feedback',              [FeedbackController::class, 'store']);
    Route::get('admin/feedback',         [FeedbackController::class, 'index']);
    Route::patch('admin/feedback/{id}',  [FeedbackController::class, 'update']);

    Route::get('invoices',                [InvoiceController::class, 'index']);
    Route::post('invoices',               [InvoiceController::class, 'store']);
    // Refinitiv (LSEG) 注文書 PDF 取込フロー
    Route::post('invoices/refinitiv/parse', [RefinitivInvoiceController::class, 'parse']);
    Route::post('invoices/refinitiv/issue', [RefinitivInvoiceController::class, 'issue']);
    // 捺印スキャンPDF (承認後の紙→印鑑→スキャン PDF 取込)
    Route::post('invoices/signed-scan/scan',    [InvoiceController::class, 'signedScanScan']);
    Route::post('invoices/signed-scan/confirm', [InvoiceController::class, 'signedScanConfirm']);
    Route::get('invoices/{invoice}/signed-scan/download', [InvoiceController::class, 'signedScanDownload']);
    Route::get('invoices/{invoice}',      [InvoiceController::class, 'show']);
    Route::put('invoices/{invoice}',      [InvoiceController::class, 'update']);
    Route::delete('invoices/{invoice}',   [InvoiceController::class, 'destroy']);
    Route::post('invoices/{invoice}/duplicate',    [InvoiceController::class, 'duplicate']);
    Route::post('invoices/{invoice}/pdf',          [InvoiceController::class, 'generatePdf']);
    Route::post('invoices/{invoice}/submit-approval', [InvoiceController::class, 'submitForApproval']);
    Route::post('invoices/{invoice}/approve',      [InvoiceController::class, 'approve']);
    Route::post('invoices/{invoice}/reject',       [InvoiceController::class, 'reject']);
    Route::post('invoices/{invoice}/cover-letter', [InvoiceController::class, 'coverLetter']);
    Route::get('invoices/{invoice}/envelope',      [InvoiceController::class, 'envelope']);
    Route::post('invoices/{invoice}/send-mail',    [InvoiceController::class, 'sendMail']);
    Route::post('invoices/{invoice}/record-post',  [InvoiceController::class, 'recordPost']);
    Route::get('invoices/{invoice}/latest-post',   [InvoiceController::class, 'latestPost']);
    Route::get('invoices/{invoice}/mail-template', [InvoiceController::class, 'mailTemplate']);
    Route::get('invoices/{invoice}/send-histories',[InvoiceController::class, 'sendHistories']);
    Route::get('invoice-send-histories',           [InvoiceController::class, 'allSendHistories']);

    // ── 見積書（doc_type='estimate'）─────────────────────
    // 一覧/取得/更新/削除/PDF生成/承認/送信履歴/メール は InvoiceController を流用。
    // 一覧は doc_type=estimate を強制する想定でフロント側がクエリパラメータを付与する。
    Route::post('estimates', [InvoiceController::class, 'storeEstimate']);
    // 英文モード時の件名英訳プレビュー
    Route::post('estimates/translate-title', [InvoiceController::class, 'translateTitle']);

    // ── 注文書（doc_type='purchase_order'）+ 注文請書 ─────
    // 作成のみ独立エンドポイント。一覧/取得/更新/PDF生成は InvoiceController を流用。
    // PDF生成時 (invoices/{id}/pdf) は注文書 PDF と注文請書 PDF を同時に生成する。
    Route::post('purchase-orders', [InvoiceController::class, 'storePurchaseOrder']);
    Route::get('email-body-templates/me',  [EmailBodyTemplateController::class, 'show']);
    Route::put('email-body-templates/me',  [EmailBodyTemplateController::class, 'upsert']);
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/mark-read', [NotificationController::class, 'markRead']);

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
    Route::post('cards/detect', [BusinessCardController::class, 'detect']); // 1画像内複数名刺を分割
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
        Route::get('/self-owners',   [EmailController::class, 'selfOwners']); // 自社タブの担当者(from ローカル部)一覧
        Route::post('/sync',         [EmailController::class, 'sync']);
        Route::post('/mark-all-read',     [EmailController::class, 'markAllRead']);    // 全件既読 (非同期ジョブ登録 202)
        Route::get ('/mark-read-status',  [EmailController::class, 'markReadStatus']); // 全件既読の進捗ポーリング
        Route::get('/{id}',                                      [EmailController::class, 'show']);
        Route::patch('/{id}/link',                               [EmailController::class, 'link']);
        Route::get('/{id}/attachments/{attachmentId}/download',  [EmailController::class, 'downloadAttachment']);
        Route::post('/{id}/reply',                               [EmailController::class, 'reply']); // SelfMailsView 返信送信 (E-4)
        Route::delete('/{id}',                                   [EmailController::class, 'destroy']);
    });

    // ── 案件メール（スコアリング済み）────────────────────
    Route::prefix('project-mails')->group(function () {
        Route::get('/',              [ProjectMailController::class, 'index']);
        Route::post('/manual',       [ProjectMailController::class, 'storeManual']); // E-3 手動登録
        Route::post('/rescore-all',   [ProjectMailController::class, 'rescoreAll']);
        Route::get('/rescore-status', [ProjectMailController::class, 'rescoreStatus']);
        Route::post('/reextract-all', [ProjectMailController::class, 'reextractAll']);
        Route::get('/{id}',          [ProjectMailController::class, 'show']);
        Route::patch('/{id}',        [ProjectMailController::class, 'update']);
        Route::patch('/{id}/status', [ProjectMailController::class, 'updateStatus']);
        Route::post('/{id}/rescore',              [ProjectMailController::class, 'rescore']);
        Route::get('/{id}/thread',                [ProjectMailController::class, 'thread']);
        Route::get('/{id}/matched-engineers',     [ProjectMailController::class, 'matchedEngineers']);
        Route::post('/{id}/generate-proposal',    [ProjectMailController::class, 'generateProposal']);
        Route::post('/{id}/send-proposal',        [ProjectMailController::class, 'sendProposal']);
        Route::post('/{id}/send-bulk',            [ProjectMailController::class, 'sendBulk']);
        // 鮮度マッチング（過去N日メール候補）
        Route::get('/{id}/fresh-engineer-mails',  [ProjectMailController::class, 'freshEngineerMails']);
        Route::post('/{id}/send-proposal-from-ems', [ProjectMailController::class, 'sendProposalFromEms']);
        // 要件マッチング (docs/480)
        Route::get('/{id}/requirements',                       [ProjectMailController::class, 'requirements']);
        Route::post('/{id}/requirements/regenerate',           [ProjectMailController::class, 'regenerateRequirements']);
        Route::get('/{id}/requirement-match',                  [ProjectMailController::class, 'requirementMatch']);
        Route::post('/{id}/requirement-match/regenerate',      [ProjectMailController::class, 'regenerateRequirementMatch']);
        Route::post('/{id}/requirement-match-batch',           [ProjectMailController::class, 'requirementMatchBatch']);
    });

    // ── 技術者メール（スコアリング済み）──────────────────
    Route::prefix('engineer-mails')->group(function () {
        Route::get('/',              [EngineerMailController::class, 'index']);
        Route::post('/manual',       [EngineerMailController::class, 'storeManual']); // E-3 手動登録
        Route::post('/rescore-all',  [EngineerMailController::class, 'rescoreAll']);
        Route::get('/rescore-status', [EngineerMailController::class, 'rescoreStatus']);
        Route::get('/{id}',                           [EngineerMailController::class, 'show']);
        Route::get('/{id}/attachment/{attachmentId}', [EngineerMailController::class, 'downloadAttachment']);
        Route::put('/{id}',                           [EngineerMailController::class, 'update']);
        Route::put('/{id}/status',                    [EngineerMailController::class, 'updateStatus']);
        Route::post('/{id}/register-engineer',        [EngineerMailController::class, 'registerEngineer']);
        Route::get('/{id}/thread',                    [EngineerMailController::class, 'thread']);
        Route::get('/{id}/matched-projects',          [EngineerMailController::class, 'matchedProjects']);
        Route::post('/{id}/generate-proposal',        [EngineerMailController::class, 'generateProposal']);
        Route::post('/{id}/send-proposal',            [EngineerMailController::class, 'sendProposal']);
        Route::post('/{id}/generate-comment',         [EngineerMailController::class, 'generateComment']);
        // 鮮度マッチング（過去N日メール候補）
        Route::get('/{id}/fresh-project-mails',   [EngineerMailController::class, 'freshProjectMails']);
        Route::post('/{id}/send-proposal-from-pms', [EngineerMailController::class, 'sendProposalFromPms']);
        // まとめて提案: BP(EMS送信者) 宛て
        Route::post('/{id}/send-bulk-to-bp',        [EngineerMailController::class, 'sendBulkToBp']);
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
    Route::post('public-projects/{id}/generate-appeal', [PublicProjectController::class, 'generateAppeal']);
    Route::post('public-projects/{id}/send-proposal', [PublicProjectController::class, 'sendProposal']);
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
        Route::post('/bulk-set-active',[DeliveryAddressController::class, 'bulkSetActive']);
        Route::post('/save-state',     [DeliveryAddressController::class, 'saveState']);
        Route::post('/restore-state',  [DeliveryAddressController::class, 'restoreState']);
        Route::patch('/{id}',          [DeliveryAddressController::class, 'update']);
        Route::delete('/{id}',         [DeliveryAddressController::class, 'destroy']);
    });

    // ── 提案スレッド一覧 ──────────────────────────────────
    Route::get('proposal-threads', [DeliveryCampaignController::class, 'proposalThreads']);

    // ── 要件マッチング (docs/480 §5) 営業手動上書き ───────────
    Route::patch('requirement-match-results/{id}', [RequirementMatchResultController::class, 'update']);

    // ── 配信キャンペーン（送信履歴を統合）───────────────────
    Route::prefix('delivery-campaigns')->group(function () {
        Route::get('/',              [DeliveryCampaignController::class, 'index']);
        Route::post('/check-duplicates', [DeliveryCampaignController::class, 'checkDuplicates']);
        Route::post('/',             [DeliveryCampaignController::class, 'store']);
        Route::get('/{id}',          [DeliveryCampaignController::class, 'show']);
        Route::get('/{id}/progress', [DeliveryCampaignController::class, 'progress']);
        Route::post('/{campaignId}/histories/{historyId}/resend', [DeliveryCampaignController::class, 'resendHistory']);
        Route::post('/{id}/send-reply',  [DeliveryCampaignController::class, 'sendReply']);
        Route::post('/{id}/resend-bulk', [DeliveryCampaignController::class, 'resendBulk']);
    });
});
