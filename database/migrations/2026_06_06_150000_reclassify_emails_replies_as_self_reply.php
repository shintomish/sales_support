<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 過去の /emails 個別返信 (send_type='delivery') を 'self_reply' に再分類する。
 *
 * /emails の返信(EmailController::reply)は send_type='delivery' で記録され、一斉配信履歴に
 * 混ざっていた。これを専用「返信履歴」タブに分離するため send_type を切り替える。
 *
 * 判別: send_type='delivery' のうち、送信履歴の delivery_address_id が「1件も設定されていない」もの。
 *   - /deliveries の一斉/単発配信は配信先リスト宛なので delivery_address_id がセットされる。
 *   - /emails 返信は任意アドレス宛なので delivery_address_id が null。
 * 本番では #56 #57 のちょうど2件が該当（2026-06-06 確認）。冪等。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;

        DB::statement(<<<'SQL'
            UPDATE delivery_campaigns c
            SET send_type = 'self_reply'
            WHERE c.send_type = 'delivery'
              AND EXISTS (
                    SELECT 1 FROM delivery_send_histories h
                    WHERE h.campaign_id = c.id AND h.delivery_address_id IS NULL
                  )
              AND NOT EXISTS (
                    SELECT 1 FROM delivery_send_histories h
                    WHERE h.campaign_id = c.id AND h.delivery_address_id IS NOT NULL
                  )
        SQL);
    }

    public function down(): void
    {
        // self_reply → delivery への巻き戻し（再分類のため down は緩め・冪等）。
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement("UPDATE delivery_campaigns SET send_type = 'delivery' WHERE send_type = 'self_reply'");
    }
};
