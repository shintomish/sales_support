<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // 請求書番号の [COM] 部分用の顧客コード（例: REFI / INTG）
            $table->string('invoice_code', 20)->nullable()->after('company_name');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('invoice_code');
        });
    }
};
