<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 案件閲覧履歴
 *
 * 誰がどの案件をいつ閲覧したかを記録する。
 * public_projects.views_count の集計元となり、レコメンド改善にも活用する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()
                ->comment('テナントID（多テナント分離用）');
            $table->unsignedBigInteger('project_id')
                ->comment('public_projects テーブルへの外部キー');
            $table->unsignedBigInteger('viewer_user_id')->nullable()
                ->comment('閲覧したユーザー（未ログインの場合は null）');

            $table->timestamp('viewed_at')->useCurrent()->comment('閲覧日時');

            $table->foreign('project_id')
                ->references('id')
                ->on('public_projects')
                ->onDelete('cascade');

            $table->foreign('viewer_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->index(['project_id', 'viewed_at'],
                'idx_project_views_project_time');
            $table->index(['tenant_id', 'viewer_user_id'],
                'idx_project_views_tenant_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_views');
    }
};
