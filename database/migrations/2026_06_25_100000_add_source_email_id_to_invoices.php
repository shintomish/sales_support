<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoices に source_email_id を追加（見積の起点となった受信メールへの参照）。
 *
 * 客先から partner@aizen-sol.co.jp 宛に届いた「見積依頼」メールから見積書を作成した際、
 * その依頼メールを見積に紐付け、受信(依頼)→見積→送信/郵送(返信) の記録を一元化する。
 *   - 1見積 = 起点メール1通（返信群は emails.rfc_message_id の RFC スレッドで辿る）
 *   - メール削除時は null 化（見積自体は残す）
 *
 * 既存テーブルへのカラム追加のため RLS/GRANT は invoices の既存設定をそのまま継承（新規付与不要）。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('source_email_id')
                ->nullable()
                ->after('deal_id')
                ->comment('見積の起点となった受信メール (emails.id)')
                ->constrained('emails')
                ->nullOnDelete();
            $table->index('source_email_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_email_id');
        });
    }
};
