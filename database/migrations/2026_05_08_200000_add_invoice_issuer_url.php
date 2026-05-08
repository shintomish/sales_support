<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 請求書発行元 URL（送付状・封筒に表示）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('invoice_issuer_url', 255)->nullable()->after('invoice_issuer_seal_path');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('issuer_url_snapshot', 255)->nullable()->after('issuer_seal_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('invoice_issuer_url');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('issuer_url_snapshot');
        });
    }
};
