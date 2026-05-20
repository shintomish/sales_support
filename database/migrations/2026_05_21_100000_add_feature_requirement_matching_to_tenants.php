<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 案件要件 × 技術者スキル 対照表機能 (docs/480) のテナント別 Feature Flag。
 * Phase 1: 全テナント false で開始。アルファ用に松村テナント (アイゼン・ソリューション)
 * のみ true にする運用。GA で全テナント true。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('feature_requirement_matching')->default(false)->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('feature_requirement_matching');
        });
    }
};
