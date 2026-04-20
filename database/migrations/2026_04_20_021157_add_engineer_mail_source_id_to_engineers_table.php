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
            $table->unsignedBigInteger('engineer_mail_source_id')->nullable()->after('affiliation_type');
            $table->index('engineer_mail_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('engineers', function (Blueprint $table) {
            $table->dropIndex(['engineer_mail_source_id']);
            $table->dropColumn('engineer_mail_source_id');
        });
    }
};
