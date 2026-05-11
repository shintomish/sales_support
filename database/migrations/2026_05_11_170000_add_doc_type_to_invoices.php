<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * invoices テーブルに doc_type / valid_until_text を追加
 *  - doc_type: 'invoice' (請求書) | 'estimate' (見積書) | 'purchase_order' (注文書)
 *  - valid_until_text: 見積書の有効期間（"30日間" 等のフリー文字列）
 *
 * 既存行はすべて doc_type='invoice' で初期化する。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('doc_type', 20)->default('invoice')->after('tenant_id');
            $table->string('valid_until_text', 50)->nullable()->after('due_date');
        });

        // 既存データのバックフィル（明示的に invoice を入れる）
        DB::statement("UPDATE invoices SET doc_type = 'invoice' WHERE doc_type IS NULL OR doc_type = ''");
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['doc_type', 'valid_until_text']);
        });
    }
};
