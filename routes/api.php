<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\BusinessCardController;

// ── 認証不要 ────────────────────────────────────────
Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

// ── 認証必須 ────────────────────────────────────────
Route::prefix('v1')->middleware(['web', 'auth'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

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

    // 名刺OCR
    Route::apiResource('cards', BusinessCardController::class)->names([
        'index' => 'api.cards.index',
        'store' => 'api.cards.store',
        'show' => 'api.cards.show',
        'destroy' => 'api.cards.destroy',
    ])->only(['index', 'store', 'show', 'destroy']); // updateは不要
});
