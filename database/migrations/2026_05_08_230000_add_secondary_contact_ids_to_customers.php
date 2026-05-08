<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * customers に副担当者ID配列（最大4名）を追加。
 * 主担当者(primary_contact_id) + 副担当者(secondary_contact_ids[0..3]) で 担当者2〜5 を表現。
 *
 * JSON 配列で contacts.id の整数を 0〜4 件保持。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->json('secondary_contact_ids')->nullable()->after('primary_contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('secondary_contact_ids');
        });
    }
};
