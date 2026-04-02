<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->string('category', 20)->nullable()->after('is_read');     // engineer/project/unknown
            $table->jsonb('extracted_data')->nullable()->after('category');   // Claude抽出結果
            $table->timestamp('classified_at')->nullable()->after('extracted_data'); // 分類実行日時
            $table->timestamp('registered_at')->nullable()->after('classified_at');  // 登録済み日時
            $table->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'category']);
            $table->dropColumn(['category', 'extracted_data', 'classified_at', 'registered_at']);
        });
    }
};
