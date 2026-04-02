<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            // 登録先の追跡用（どのengineer/projectが生成されたか）
            $table->foreignId('registered_engineer_id')
                ->nullable()->after('registered_at')
                ->constrained('engineers')->onDelete('set null');
            $table->foreignId('registered_project_id')
                ->nullable()->after('registered_engineer_id')
                ->constrained('public_projects')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropForeign(['registered_engineer_id']);
            $table->dropForeign(['registered_project_id']);
            $table->dropColumn(['registered_engineer_id', 'registered_project_id']);
        });
    }
};
