<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 添付スキルシート (Excel/PDF) のテキスト抽出結果を保持する。Stage 2 判定の精度向上用 (docs/480 §3.3)。
 * 抽出パイプライン自体は Phase 4 で実装予定。本 migration では空のままでも判定は動作する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engineer_mail_sources', function (Blueprint $table) {
            $table->text('parsed_skill_sheet_text')->nullable()->after('skills');
        });
    }

    public function down(): void
    {
        Schema::table('engineer_mail_sources', function (Blueprint $table) {
            $table->dropColumn('parsed_skill_sheet_text');
        });
    }
};
