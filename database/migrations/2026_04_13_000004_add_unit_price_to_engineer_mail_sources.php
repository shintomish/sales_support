<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engineer_mail_sources', function (Blueprint $table) {
            $table->unsignedSmallInteger('unit_price_min')
                  ->nullable()
                  ->after('age')
                  ->comment('希望単価下限（万円/月）');

            $table->unsignedSmallInteger('unit_price_max')
                  ->nullable()
                  ->after('unit_price_min')
                  ->comment('希望単価上限（万円/月）');
        });
    }

    public function down(): void
    {
        Schema::table('engineer_mail_sources', function (Blueprint $table) {
            $table->dropColumn(['unit_price_min', 'unit_price_max']);
        });
    }
};
