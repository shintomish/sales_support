<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 捺印スキャンPDF (承認後の紙印刷→物理印→スキャン)
 *  - signed_scan_pdf_path   : Supabase Storage `signed-scans` バケット内の path
 *  - signed_scan_uploaded_at: 最終アップロード日時 (上書き運用)
 *  - signed_scan_uploaded_by: アップロードしたユーザー ID
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('signed_scan_pdf_path', 1024)->nullable()->after('acknowledgement_pdf_path');
            $table->timestamp('signed_scan_uploaded_at')->nullable()->after('signed_scan_pdf_path');
            $table->unsignedBigInteger('signed_scan_uploaded_by')->nullable()->after('signed_scan_uploaded_at');
            $table->index(['tenant_id', 'signed_scan_uploaded_at'], 'idx_invoices_tenant_signed_scan');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_tenant_signed_scan');
            $table->dropColumn(['signed_scan_pdf_path', 'signed_scan_uploaded_at', 'signed_scan_uploaded_by']);
        });
    }
};
