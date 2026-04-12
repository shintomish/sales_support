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
        Schema::table('mail_send_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('engineer_id')->nullable()->after('project_mail_id')->index();
            $table->unsignedBigInteger('public_project_id')->nullable()->after('engineer_id')->index();

            $table->foreign('engineer_id')->references('id')->on('engineers')->onDelete('set null');
            $table->foreign('public_project_id')->references('id')->on('public_projects')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_send_histories', function (Blueprint $table) {
            $table->dropForeign(['engineer_id']);
            $table->dropForeign(['public_project_id']);
            $table->dropColumn(['engineer_id', 'public_project_id']);
        });
    }
};
