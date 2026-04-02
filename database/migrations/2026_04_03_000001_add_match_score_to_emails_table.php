<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->integer('best_match_score')->nullable()->after('registered_project_id');
            $table->integer('match_count')->default(0)->after('best_match_score');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn(['best_match_score', 'match_count']);
        });
    }
};
