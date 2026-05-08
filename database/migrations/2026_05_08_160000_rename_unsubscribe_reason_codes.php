<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 停止理由のコード名を業務に合わせてリネーム。
 *
 * 旧 self_unsubscribed (リンク経由 = 受信者本人) → 新 recipient_unsubscribed
 * 旧 user_disabled    (オペレーター手動)        → 新 operator_disabled
 *
 * 表示ラベルは別途フロントで:
 *   recipient_unsubscribed → 「客先により停止」
 *   operator_disabled       → 「担当者による停止」
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('delivery_addresses')
            ->where('unsubscribe_reason', 'self_unsubscribed')
            ->update(['unsubscribe_reason' => 'recipient_unsubscribed']);

        DB::table('delivery_addresses')
            ->where('unsubscribe_reason', 'user_disabled')
            ->update(['unsubscribe_reason' => 'operator_disabled']);
    }

    public function down(): void
    {
        DB::table('delivery_addresses')
            ->where('unsubscribe_reason', 'recipient_unsubscribed')
            ->update(['unsubscribe_reason' => 'self_unsubscribed']);

        DB::table('delivery_addresses')
            ->where('unsubscribe_reason', 'operator_disabled')
            ->update(['unsubscribe_reason' => 'user_disabled']);
    }
};
