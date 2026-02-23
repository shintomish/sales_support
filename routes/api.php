<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\TaskController;

// ── 認証不要 ────────────────────────────────────────
Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

// ── 認証必須 ────────────────────────────────────────
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    // 各リソースのCRUDエンドポイント
    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('contacts', ContactController::class);
    Route::apiResource('deals', DealController::class);
    Route::apiResource('activities', ActivityController::class);
    Route::apiResource('tasks', TaskController::class);
});
