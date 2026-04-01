<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * お気に入り案件
 *
 * ユーザーが案件をお気に入り登録した情報を管理する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorite_projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()
                ->comment('テナントID（多テナント分離用）');
            $table->unsignedBigInteger('user_id')
                ->comment('お気に入り登録したユーザー（users テーブル参照）');
            $table->unsignedBigInteger('project_id')
                ->comment('お気に入り登録した案件（public_projects テーブル参照）');

            $table->timestamps();

            $table->unique(['user_id', 'project_id'], 'uq_favorite_projects_user_project');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('project_id')
                ->references('id')
                ->on('public_projects')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorite_projects');
    }
};
