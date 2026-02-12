<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DealController;

Route::get('/', function () {
    return view('welcome');
});

// ダッシュボード
Route::get('/', [DashboardController::class, 'index']);

// 顧客管理
Route::resource('customers', CustomerController::class);

// 商談管理
Route::resource('deals', DealController::class);

// 顧客に紐づく担当者取得（Ajax）
Route::get('api/contacts', [DealController::class, 'getContacts'])->name('api.contacts');

