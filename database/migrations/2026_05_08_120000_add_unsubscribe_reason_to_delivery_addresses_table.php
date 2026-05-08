<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_addresses', function (Blueprint $table) {
            // self_unsubscribed / user_disabled / system など。NULL=未設定（過去データ）
            $table->string('unsubscribe_reason', 50)->nullable()->after('is_active');
            $table->timestamp('unsubscribed_at')->nullable()->after('unsubscribe_reason');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_addresses', function (Blueprint $table) {
            $table->dropColumn(['unsubscribe_reason', 'unsubscribed_at']);
        });
    }
};
