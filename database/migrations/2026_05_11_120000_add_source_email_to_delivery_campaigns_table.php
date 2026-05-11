<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_campaigns', function (Blueprint $table) {
            // 紐づき案件/技術者メールがない新規配信で、手動入力された入手元メールアドレス。
            // 再送信時に元請けドメイン一致警告のソースとして使う。
            $table->string('source_email')->nullable()->after('engineer_mail_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_campaigns', function (Blueprint $table) {
            $table->dropColumn('source_email');
        });
    }
};
