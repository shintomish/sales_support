<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\ActivityController;

Route::get('/', function () {
    return view('welcome');
});

// ダッシュボード
Route::get('/', [DashboardController::class, 'index']);

// 顧客管理
Route::resource('customers', CustomerController::class);

// 担当者管理
Route::resource('contacts', ContactController::class);

// 商談管理
Route::resource('deals', DealController::class);

// 活動履歴管理
Route::resource('activities', ActivityController::class);

// Ajax
// 顧客に紐づく担当者取得（Ajax）
Route::get('api/contacts', [DealController::class, 'getContacts'])->name('api.contacts');
Route::get('api/customer-data', [ActivityController::class, 'getCustomerData'])->name('api.customer_data');

