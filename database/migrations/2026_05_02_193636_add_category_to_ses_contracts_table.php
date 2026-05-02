<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ses_contracts に category カラムを追加
 * 値: 'engineer' (技術者) / 'project' (案件)
 * NULL の場合は engineer_name のヒューリスティック判定にフォールバック。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ses_contracts', function (Blueprint $table) {
            $table->string('category', 20)->nullable()->after('engineer_phone')
                ->comment('分類: engineer=技術者付きSES / project=案件のみ');
        });
    }

    public function down(): void
    {
        Schema::table('ses_contracts', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
