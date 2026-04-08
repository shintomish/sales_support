<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('engineers', function (Blueprint $table) {
            $table->string('gender', 20)->nullable()
                ->comment('性別: male=男性, female=女性, other=その他, unanswered=回答しない')
                ->after('age');
            $table->string('nearest_station', 100)->nullable()
                ->comment('最寄駅（例: 渋谷駅）')
                ->after('nationality');
        });
    }

    public function down(): void
    {
        Schema::table('engineers', function (Blueprint $table) {
            $table->dropColumn(['gender', 'nearest_station']);
        });
    }
};
