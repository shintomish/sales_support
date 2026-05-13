<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * invoices テーブルに language を追加
 *  - language: 'ja' | 'en'  （帳票の表記言語、現状は見積書のみ 'en' を許容）
 *
 * 既存行は language='ja' で初期化する。
 * 件名は language 別に subject_name 1列で運用する（英文時はそのまま英文を保存）。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('language', 2)->default('ja')->after('doc_type');
        });

        DB::statement("UPDATE invoices SET language = 'ja' WHERE language IS NULL OR language = ''");
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
