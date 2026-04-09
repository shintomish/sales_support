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
        Schema::table('email_body_templates', function (Blueprint $table) {
            $table->text('body_text')->nullable()->after('mobile')->comment('プレビュー編集済みテンプレート（●● 様・（本文）がプレースホルダー）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_body_templates', function (Blueprint $table) {
            $table->dropColumn('body_text');
        });
    }
};
