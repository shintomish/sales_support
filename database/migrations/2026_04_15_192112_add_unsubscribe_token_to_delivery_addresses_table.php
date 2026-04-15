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
        Schema::table('delivery_addresses', function (Blueprint $table) {
            $table->uuid('unsubscribe_token')->nullable()->unique()->after('is_active');
        });

        // 既存レコードにトークンを付与
        DB::table('delivery_addresses')->whereNull('unsubscribe_token')->orderBy('id')->each(function ($row) {
            DB::table('delivery_addresses')
                ->where('id', $row->id)
                ->update(['unsubscribe_token' => \Illuminate\Support\Str::uuid()->toString()]);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_addresses', function (Blueprint $table) {
            $table->dropColumn('unsubscribe_token');
        });
    }
};
