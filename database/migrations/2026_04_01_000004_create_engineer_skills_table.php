<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 技術者スキル（中間テーブル）
 *
 * engineers と skills の多対多を管理する。
 * 経験年数・習熟度をもたせることでマッチングスコア計算に使用する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineer_skills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()
                ->comment('テナントID（多テナント分離用）');
            $table->unsignedBigInteger('engineer_id')
                ->comment('engineers テーブルへの外部キー');
            $table->unsignedBigInteger('skill_id')
                ->comment('skills テーブルへの外部キー');

            $table->decimal('experience_years', 3, 1)->default(0)
                ->comment('経験年数（0.5 刻みで入力可）');
            $table->tinyInteger('proficiency_level')->default(3)
                ->comment('習熟度 1-5（1:入門 / 3:実務 / 5:エキスパート）');

            $table->timestamps();

            $table->unique(['engineer_id', 'skill_id']);

            $table->foreign('engineer_id')
                ->references('id')
                ->on('engineers')
                ->onDelete('cascade');

            $table->foreign('skill_id')
                ->references('id')
                ->on('skills')
                ->onDelete('cascade');

            $table->index(['tenant_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineer_skills');
    }
};
