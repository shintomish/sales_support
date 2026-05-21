<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * メール送信時の From ヘッダ表示名をユーザ別に選べるようにする。
 * env MAIL_FROM_NAME (現状 "Aizen Solution SES Support") は最終フォールバック。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_body_templates', function (Blueprint $table) {
            $table->string('sender_display_name', 200)->nullable()->after('mobile');
        });
    }

    public function down(): void
    {
        Schema::table('email_body_templates', function (Blueprint $table) {
            $table->dropColumn('sender_display_name');
        });
    }
};
