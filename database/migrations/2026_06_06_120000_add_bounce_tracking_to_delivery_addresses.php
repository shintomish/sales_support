<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * delivery_addresses にバウンス累積トラッキングを追加。
 *
 * ハードバウンス(5.x.x)は1回で自動停止するが、このリストの実バウンスは大半が
 * 4.4.7「Message expired / Unable to lookup DNS」(= 14時間配送試行後の give-up、技術的には一時)。
 * 1回の期限切れは相手サーバーの一時ダウンの可能性もあるため、同一宛先の期限切れ回数を数え、
 * 閾値(既定2回)に達したら「実質死に」とみなして自動停止する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_addresses', function (Blueprint $table) {
            $table->unsignedInteger('soft_bounce_count')->default(0)->after('unsubscribed_at')
                ->comment('期限切れ等ソフトバウンスの累積回数(閾値で自動停止)');
            $table->timestamp('last_bounce_at')->nullable()->after('soft_bounce_count')
                ->comment('最後にバウンスを受けた日時');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_addresses', function (Blueprint $table) {
            $table->dropColumn(['soft_bounce_count', 'last_bounce_at']);
        });
    }
};
