<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('client_contact', 100)->nullable()->after('invoice_number')->comment('客先担当者');
            $table->string('client_mobile',  50)->nullable()->after('client_contact')->comment('客先携帯');
            $table->string('client_phone',   50)->nullable()->after('client_mobile')->comment('客先TEL');
            $table->string('client_fax',     50)->nullable()->after('client_phone')->comment('客先FAX');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['client_contact', 'client_mobile', 'client_phone', 'client_fax']);
        });
    }
};
