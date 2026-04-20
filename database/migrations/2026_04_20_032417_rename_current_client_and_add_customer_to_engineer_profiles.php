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
            $table->renameColumn('current_client', 'current_project');
        });
        Schema::table('engineer_profiles', function (Blueprint $table) {
            $table->string('current_customer', 200)->nullable()->after('current_project');
        });
    }

    public function down(): void
    {
        Schema::table('engineer_profiles', function (Blueprint $table) {
            $table->dropColumn('current_customer');
        });
        Schema::table('engineer_profiles', function (Blueprint $table) {
            $table->renameColumn('current_project', 'current_client');
        });
    }
};
