<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IMAP 取込時の MIME part 連番を保存する。
     * 同一メール内に同名添付（unknown.bin / attachment.pdf 等）がある場合の
     * 衝突解消・再取得用の安定キー。既存レコードは null（順序不明）。
     */
    public function up(): void
    {
        Schema::table('email_attachments', function (Blueprint $table) {
            $table->unsignedSmallInteger('part_index')->nullable()->after('storage_path');
        });
    }

    public function down(): void
    {
        Schema::table('email_attachments', function (Blueprint $table) {
            $table->dropColumn('part_index');
        });
    }
};
