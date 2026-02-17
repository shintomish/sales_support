<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\TaskController;

Route::middleware(['auth'])->group(function () {

    // ダッシュボード
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // 顧客管理
    Route::resource('customers', CustomerController::class);

    // 担当者管理
    Route::resource('contacts', ContactController::class);

    // 商談管理
    Route::resource('deals', DealController::class);

    // 活動履歴管理
    Route::resource('activities', ActivityController::class);

    // タスク管理
    Route::resource('tasks', TaskController::class);
    Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('tasks.updateStatus');

    // Ajax
    Route::get('api/contacts', [DealController::class, 'getContacts'])->name('api.contacts');
    Route::get('api/customer-data', [ActivityController::class, 'getCustomerData'])->name('api.customer_data');
});

require __DIR__.'/auth.php';