<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['customers', 'contacts', 'deals', 'tasks', 'activities', 'business_cards'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete()->after('id');
            });
        }
    }

    public function down(): void
    {
        $tables = ['customers', 'contacts', 'deals', 'tasks', 'activities', 'business_cards'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['tenant_id']);
                $t->dropColumn('tenant_id');
            });
        }
    }
};
