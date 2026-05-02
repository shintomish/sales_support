<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_records', function (Blueprint $table) {
            // 月の実労働時間（請求金額算出に使用）
            $table->decimal('actual_hours', 6, 2)->nullable()->after('paid_leave_days');
        });
    }

    public function down(): void
    {
        Schema::table('work_records', function (Blueprint $table) {
            $table->dropColumn('actual_hours');
        });
    }
};
