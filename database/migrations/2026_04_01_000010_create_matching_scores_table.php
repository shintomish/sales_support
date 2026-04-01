<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * マッチングスコア（キャッシュ）
 *
 * 案件と技術者のマッチングスコアを計算後にキャッシュするテーブル。
 * スコアは定期バッチ or 案件・プロフィール更新時に再計算する。
 * 各要素スコアを個別に保持することで、スコア根拠の説明に使用できる。
 *
 * 重み付け（デフォルト）:
 *   スキル: 50% / 単価: 25% / 勤務地: 15% / 稼働時期: 10%
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matching_scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()
                ->comment('テナントID（多テナント分離用）');
            $table->unsignedBigInteger('project_id')
                ->comment('public_projects テーブルへの外部キー');
            $table->unsignedBigInteger('engineer_id')
                ->comment('engineers テーブルへの外部キー');

            $table->decimal('score', 5, 2)->comment('総合スコア（0-100）');
            $table->decimal('skill_match_score', 5, 2)->nullable()
                ->comment('スキル適合スコア（0-100）');
            $table->decimal('price_match_score', 5, 2)->nullable()
                ->comment('単価適合スコア（0-100）');
            $table->decimal('location_match_score', 5, 2)->nullable()
                ->comment('勤務地適合スコア（0-100）');
            $table->decimal('availability_match_score', 5, 2)->nullable()
                ->comment('稼働時期適合スコア（0-100）');

            $table->timestamp('calculated_at')->useCurrent()
                ->comment('スコア計算日時');

            $table->unique(['project_id', 'engineer_id'],
                'uq_matching_scores_project_engineer');

            $table->foreign('project_id')
                ->references('id')
                ->on('public_projects')
                ->onDelete('cascade');

            $table->foreign('engineer_id')
                ->references('id')
                ->on('engineers')
                ->onDelete('cascade');

            // レコメンド表示に使う（案件→上位技術者、技術者→上位案件）
            $table->index(['tenant_id', 'project_id', 'score'],
                'idx_matching_project_score');
            $table->index(['tenant_id', 'engineer_id', 'score'],
                'idx_matching_engineer_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matching_scores');
    }
};
