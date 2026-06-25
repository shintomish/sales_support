<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * invoice_send_histories.method に 'partner' を追加（⑤ 送信履歴統合 2026-06-24 管理部）。
 *   mail   = メール送信（SES 実送信）
 *   post   = 郵送記録
 *   partner= partner@ 経由送信の記録（実送信なし・添付ファイル保存）
 *
 * Laravel の enum() は Postgres では varchar + CHECK 制約。制約を貼り替えるだけで既存データ影響なし。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE invoice_send_histories DROP CONSTRAINT IF EXISTS invoice_send_histories_method_check');
        DB::statement("ALTER TABLE invoice_send_histories ADD CONSTRAINT invoice_send_histories_method_check CHECK (method::text = ANY (ARRAY['mail'::text, 'post'::text, 'partner'::text]))");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE invoice_send_histories DROP CONSTRAINT IF EXISTS invoice_send_histories_method_check');
        DB::statement("ALTER TABLE invoice_send_histories ADD CONSTRAINT invoice_send_histories_method_check CHECK (method::text = ANY (ARRAY['mail'::text, 'post'::text]))");
    }
};
