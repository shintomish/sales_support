<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ses_contracts', function (Blueprint $table) {
            $table->string('engineer_name', 100)->nullable()->after('deal_id');
            $table->string('engineer_email', 200)->nullable()->after('engineer_name');
            $table->string('engineer_phone', 50)->nullable()->after('engineer_email');
        });
    }

    public function down(): void
    {
        Schema::table('ses_contracts', function (Blueprint $table) {
            $table->dropColumn(['engineer_name', 'engineer_email', 'engineer_phone']);
        });
    }
};
