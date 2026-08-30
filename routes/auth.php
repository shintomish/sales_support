<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', fn() => abort(404))->name('register');
    Route::post('register', fn() => abort(404));

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // パスワード再設定は Supabase Auth (app.ai-mon.net) に一本化済み。
    // 旧 Blade 側は対応ビューを持たないため、register と同様 404 で閉じる
    // (ビュー不在のまま公開すると View not found の 500 になる)
    Route::get('forgot-password', fn() => abort(404))->name('password.request');
    Route::post('forgot-password', fn() => abort(404))->name('password.email');

    Route::get('reset-password/{token}', fn() => abort(404))->name('password.reset');
    Route::post('reset-password', fn() => abort(404))->name('password.store');
});

Route::middleware('auth')->group(function () {
    // メール確認・パスワード確認も同様にビュー不在。route 名は
    // verified / password.confirm ミドルウェアの参照先として残す
    Route::get('verify-email', fn() => abort(404))->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', fn() => abort(404))
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', fn() => abort(404))
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', fn() => abort(404))->name('password.confirm');

    Route::post('confirm-password', fn() => abort(404));

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
