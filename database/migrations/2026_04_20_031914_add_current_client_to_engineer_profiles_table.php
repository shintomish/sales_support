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
        Schema::table('engineer_profiles', function (Blueprint $table) {
            $table->string('current_client', 200)->nullable()->after('availability_status');
        });
    }

    public function down(): void
    {
        Schema::table('engineer_profiles', function (Blueprint $table) {
            $table->dropColumn('current_client');
        });
    }
};
