<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 営業向け「スコア内訳」表示用に score_breakdown(jsonb) を追加する。
 * 形式: [{"label": "案件確度A（…）", "points": 15}, ...]（calcScore が項目ごとの加点を記録）。
 * 既存の score_reasons はそのまま残す（後方互換）。既存行はバックフィルコマンドで埋める。
 *
 * 既存テーブルへのカラム追加のため RLS / GRANT は設定済み（テーブル権限が新カラムを包含）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_mail_sources', function (Blueprint $table) {
            $table->jsonb('score_breakdown')->nullable()->after('score_reasons');
        });
        Schema::table('engineer_mail_sources', function (Blueprint $table) {
            $table->jsonb('score_breakdown')->nullable()->after('score_reasons');
        });
    }

    public function down(): void
    {
        Schema::table('project_mail_sources', function (Blueprint $table) {
            $table->dropColumn('score_breakdown');
        });
        Schema::table('engineer_mail_sources', function (Blueprint $table) {
            $table->dropColumn('score_breakdown');
        });
    }
};
