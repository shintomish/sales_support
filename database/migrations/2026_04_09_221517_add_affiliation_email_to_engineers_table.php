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
            $table->string('affiliation_email', 300)->nullable()->after('affiliation_contact')->comment('所属会社メールアドレス');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('engineers', function (Blueprint $table) {
            $table->dropColumn('affiliation_email');
        });
    }
};
