<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 案件要件 × 技術者スキル 対照表 (docs/480 §3.1) の PMS 側カラム。
 * Stage 1 (Claude 抽出) の結果を PMS 単位でキャッシュする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_mail_sources', function (Blueprint $table) {
            $table->jsonb('ai_requirements')->nullable()->after('preferred_skills');
            $table->timestamp('ai_requirements_generated_at')->nullable()->after('ai_requirements');
        });
    }

    public function down(): void
    {
        Schema::table('project_mail_sources', function (Blueprint $table) {
            $table->dropColumn(['ai_requirements', 'ai_requirements_generated_at']);
        });
    }
};
