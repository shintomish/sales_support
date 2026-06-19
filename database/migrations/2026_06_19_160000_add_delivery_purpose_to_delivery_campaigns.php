<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 一斉配信履歴 (delivery_campaigns) に「配信目的軸」を追加する。
 *
 * 2026-06-19 営業会議 §4: 配信頻度増→スパム類似化でレスポンス低下。対策として
 * 「配信用」と「リアル案件用(超リアル)」で文面/表記を分ける方針。
 *
 * 既存の delivery_type(project|engineer) は「チャネル軸」であり、目的(配信用/リアル)とは
 * 直交する別概念。send_type(delivery/proposal/...) も増減しないため、
 * 提案スレッド系 send_type の 4箇所同期制約には抵触しない。
 *
 *   delivery_purpose:
 *     - 'standard'          通常の一斉配信
 *     - 'real_spot'         スポット(超リアル)案件。文面差別化用
 *     - 将来 'existing_customer' 等を追加可能
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_campaigns', function (Blueprint $table) {
            $table->string('delivery_purpose', 20)->nullable()->after('delivery_type');
        });

        // 既存レコードはすべて通常配信としてバックフィル。
        DB::statement("UPDATE delivery_campaigns SET delivery_purpose = 'standard' WHERE delivery_purpose IS NULL");
    }

    public function down(): void
    {
        Schema::table('delivery_campaigns', function (Blueprint $table) {
            $table->dropColumn('delivery_purpose');
        });
    }
};
