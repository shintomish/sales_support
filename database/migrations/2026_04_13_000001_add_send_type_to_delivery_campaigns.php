<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_campaigns', function (Blueprint $table) {
            $table->string('send_type', 30)
                  ->default('delivery')
                  ->after('id')
                  ->comment('delivery / proposal / bulk / matching_proposal / engineer_proposal');

            $table->unsignedBigInteger('engineer_mail_source_id')
                  ->nullable()
                  ->after('project_mail_id')
                  ->index()
                  ->comment('engineer_proposal 用');

            $table->foreign('engineer_mail_source_id')
                  ->references('id')
                  ->on('engineer_mail_sources')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_campaigns', function (Blueprint $table) {
            $table->dropForeign(['engineer_mail_source_id']);
            $table->dropColumn(['send_type', 'engineer_mail_source_id']);
        });
    }
};
