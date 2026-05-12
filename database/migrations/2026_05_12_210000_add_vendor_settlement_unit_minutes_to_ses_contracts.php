<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ses_contracts テーブルに仕入側の精算単位(分)を追加
 *
 * 既存の settlement_unit_minutes は顧客側専用として運用するが、
 * 仕入側にも独立した精算単位を持たせたい（管理部要望 2026-05-12）。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ses_contracts', function (Blueprint $table) {
            $table->smallInteger('vendor_settlement_unit_minutes')
                ->nullable()
                ->after('vendor_overtime_hours')
                ->comment('仕入側 精算単位(分)');
        });
    }

    public function down(): void
    {
        Schema::table('ses_contracts', function (Blueprint $table) {
            $table->dropColumn('vendor_settlement_unit_minutes');
        });
    }
};
