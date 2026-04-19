<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('engineer_id')
                ->nullable()
                ->after('contact_id')
                ->constrained('engineers')
                ->nullOnDelete();
            $table->index('engineer_id');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropForeign(['engineer_id']);
            $table->dropIndex(['engineer_id']);
            $table->dropColumn('engineer_id');
        });
    }
};
