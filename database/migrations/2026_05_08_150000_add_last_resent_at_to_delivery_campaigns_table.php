<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_campaigns', function (Blueprint $table) {
            // キャンペーン単位での一括再送信日時。最後に resend-bulk が走った時刻
            $table->timestamp('last_resent_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_campaigns', function (Blueprint $table) {
            $table->dropColumn('last_resent_at');
        });
    }
};
