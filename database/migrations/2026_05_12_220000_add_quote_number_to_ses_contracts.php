<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ses_contracts テーブルに 見積番号 (quote_number) を追加
 *
 * 既存の order_number (注文番号) と対をなす項目。
 * 見積書 (doc_type=estimate, 番号 EST-XXX) が SES契約に対して発行された時、
 * その invoice_number を ses_contracts.quote_number にセットして紐付けする。
 *
 * 管理部要望 (2026-05-12): 見積書作成時に SES台帳の顧客側に見積番号を反映する運用
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ses_contracts', function (Blueprint $table) {
            $table->string('quote_number', 100)
                ->nullable()
                ->after('order_number')
                ->comment('見積番号 (EST-...) - 該当案件に対して発行された見積書の番号');
        });
    }

    public function down(): void
    {
        Schema::table('ses_contracts', function (Blueprint $table) {
            $table->dropColumn('quote_number');
        });
    }
};
