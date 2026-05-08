<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 電子印画像 + SES契約 注文No.（2026-05-08）
 *
 *  - tenants.invoice_issuer_seal_path  : Supabase Storage URL
 *  - invoices.issuer_seal_snapshot     : 発行時点のスナップショット
 *  - ses_contracts.order_number        : 顧客から指定された注文番号（PO）。請求書に転記
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('invoice_issuer_seal_path', 500)->nullable()->after('invoice_issuer_logo_path')
                ->comment('電子印画像（Supabase Storage URL）');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('issuer_seal_snapshot', 500)->nullable()->after('issuer_logo_snapshot')
                ->comment('発行時の電子印画像 URL スナップショット');
        });

        Schema::table('ses_contracts', function (Blueprint $table) {
            $table->string('order_number', 100)->nullable()->after('payment_site')
                ->comment('顧客側 注文番号（PO）。請求書に転記される');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('invoice_issuer_seal_path');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('issuer_seal_snapshot');
        });
        Schema::table('ses_contracts', function (Blueprint $table) {
            $table->dropColumn('order_number');
        });
    }
};
