<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;

Route::get('/', function () {
    return view('welcome');
});

// ダッシュボード
Route::get('/', [DashboardController::class, 'index']);

// 顧客管理
Route::resource('customers', CustomerController::class);

