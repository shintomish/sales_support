<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_send_histories', function (Blueprint $table) {
            // この履歴行が再送信された日時。1 回でも再送されると最新の resent_at が入る
            $table->timestamp('resent_at')->nullable()->after('replied_at');
            // 再送で生成された行が元の行を指す
            $table->unsignedBigInteger('parent_history_id')->nullable()->after('resent_at');
            $table->index('parent_history_id');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_send_histories', function (Blueprint $table) {
            $table->dropIndex(['parent_history_id']);
            $table->dropColumn(['resent_at', 'parent_history_id']);
        });
    }
};
