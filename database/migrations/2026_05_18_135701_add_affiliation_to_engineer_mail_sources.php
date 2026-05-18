<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engineer_mail_sources', function (Blueprint $table) {
            $table->text('affiliation')
                  ->nullable()
                  ->after('affiliation_type')
                  ->comment('所属会社名（重複検出キー: email+name+affiliation の3項目一致で同一人物判定）');
        });
    }

    public function down(): void
    {
        Schema::table('engineer_mail_sources', function (Blueprint $table) {
            $table->dropColumn('affiliation');
        });
    }
};
