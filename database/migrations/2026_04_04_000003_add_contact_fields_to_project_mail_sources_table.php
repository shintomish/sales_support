<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_mail_sources', function (Blueprint $table) {
            $table->string('sales_contact', 100)->nullable()->after('customer_name');
            $table->string('phone', 50)->nullable()->after('sales_contact');
        });
    }

    public function down(): void
    {
        Schema::table('project_mail_sources', function (Blueprint $table) {
            $table->dropColumn(['sales_contact', 'phone']);
        });
    }
};
