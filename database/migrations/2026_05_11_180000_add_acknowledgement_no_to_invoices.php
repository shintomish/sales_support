<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoices テーブルに acknowledgement_no / acknowledgement_pdf_path を追加
 *  - acknowledgement_no:        注文請書の番号 (UKE-{invoice_code}-YYYYMM-NNN)
 *  - acknowledgement_pdf_path:  注文請書 PDF の Storage URL
 *  doc_type='purchase_order' の行に対し、注文書(invoice_number/pdf_path)と
 *  請書(acknowledgement_no/acknowledgement_pdf_path)の 2 セットを同時保持する。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('acknowledgement_no', 50)->nullable()->after('invoice_number');
            $table->string('acknowledgement_pdf_path', 1024)->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['acknowledgement_no', 'acknowledgement_pdf_path']);
        });
    }
};
