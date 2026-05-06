<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * feedback_reports テーブル作成（2026-05-06）
 *
 * 社内ユーザーからのバグ報告・要望を吸い上げるフィードバックフォーム用。
 * /settings/feedback から投稿し、shintomi (FEEDBACK_NOTIFY_TO) にメール通知 + DB 記録する。
 * super_admin はテナント横断で閲覧・status 更新できる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()
                ->comment('テナントID');
            $table->unsignedBigInteger('user_id')->nullable()->index()
                ->comment('投稿ユーザー（削除済を残せるよう nullable）');
            $table->string('type', 20)->default('bug')
                ->comment('種別 (bug / request / other)');
            $table->string('subject', 255)
                ->comment('件名');
            $table->text('body')
                ->comment('本文');
            $table->string('url', 500)->nullable()
                ->comment('発生画面の URL');
            $table->string('user_agent', 500)->nullable()
                ->comment('ブラウザ User-Agent');
            $table->string('status', 20)->default('new')
                ->comment('対応状態 (new / seen / closed)');
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at'], 'idx_feedback_tenant_status_created');
            $table->index(['status', 'created_at'], 'idx_feedback_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_reports');
    }
};
