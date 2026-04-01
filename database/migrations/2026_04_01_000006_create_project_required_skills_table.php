<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 案件必須スキル
 *
 * public_projects と skills の多対多。
 * is_required で「必須」と「歓迎」を区別し、
 * マッチングスコアの重み付けに使用する（必須スキルは重み2倍）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_required_skills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')
                ->comment('public_projects テーブルへの外部キー');
            $table->unsignedBigInteger('skill_id')
                ->comment('skills テーブルへの外部キー');

            $table->boolean('is_required')->default(true)
                ->comment('true: 必須スキル / false: 歓迎スキル');
            $table->decimal('min_experience_years', 3, 1)->nullable()
                ->comment('最低経験年数');

            $table->timestamps();

            $table->unique(['project_id', 'skill_id']);

            $table->foreign('project_id')
                ->references('id')
                ->on('public_projects')
                ->onDelete('cascade');

            $table->foreign('skill_id')
                ->references('id')
                ->on('skills')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_required_skills');
    }
};
