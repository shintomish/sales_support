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
        Schema::table('public_projects', function (Blueprint $table) {
            $table->unsignedBigInteger('project_mail_source_id')->nullable()->after('posted_by_user_id');
            $table->index('project_mail_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('public_projects', function (Blueprint $table) {
            $table->dropIndex(['project_mail_source_id']);
            $table->dropColumn('project_mail_source_id');
        });
    }
};
