<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_campaigns', function (Blueprint $table) {
            $table->unsignedInteger('replied_count')->default(0)->after('failed_count');
        });

        // 既存レコードの replied_count を delivery_send_histories から再計算
        // 注: テーブル別名を使わない portable な書き方（PostgreSQL / SQLite 両対応）
        DB::statement("
            UPDATE delivery_campaigns
            SET replied_count = (
                SELECT COUNT(*) FROM delivery_send_histories
                WHERE delivery_send_histories.campaign_id = delivery_campaigns.id
                  AND delivery_send_histories.status = 'replied'
            )
        ");
    }

    public function down(): void
    {
        Schema::table('delivery_campaigns', function (Blueprint $table) {
            $table->dropColumn('replied_count');
        });
    }
};
