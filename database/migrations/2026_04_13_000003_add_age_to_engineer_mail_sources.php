<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engineer_mail_sources', function (Blueprint $table) {
            $table->unsignedSmallInteger('age')
                  ->nullable()
                  ->after('name')
                  ->comment('年齢');
        });
    }

    public function down(): void
    {
        Schema::table('engineer_mail_sources', function (Blueprint $table) {
            $table->dropColumn('age');
        });
    }
};
