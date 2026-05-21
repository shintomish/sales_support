<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 一斉配信履歴 (delivery_campaigns) に「案件配信 / 技術者配信」の意図を保持する列を追加。
 * 紐づき案件メールを選ばずに送信した場合でも、ユーザがフォームで選んだ
 * deliveryType (project | engineer) を分類として残せるようにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_campaigns', function (Blueprint $table) {
            $table->string('delivery_type', 20)->nullable()->after('send_type');
        });

        // 既存レコードのバックフィル: project_mail_id ありなら project、
        // engineer_mail_source_id ありなら engineer、それ以外は NULL のまま (legacy 「配信」 扱い)。
        \Illuminate\Support\Facades\DB::statement("
            UPDATE delivery_campaigns
            SET delivery_type = CASE
                WHEN project_mail_id IS NOT NULL THEN 'project'
                WHEN engineer_mail_source_id IS NOT NULL THEN 'engineer'
                ELSE delivery_type
            END
        ");
    }

    public function down(): void
    {
        Schema::table('delivery_campaigns', function (Blueprint $table) {
            $table->dropColumn('delivery_type');
        });
    }
};
