<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * report_recipients テーブル作成（2026-05-05）
 *
 * 朝の日次レポート等、自動配信メールの宛先管理。
 * report_type で 'daily_sales' / 'weekly' / 'alert' 等を切り分け、
 * 同じ人が複数のレポートを購読できるよう (email × report_type) 単位で管理。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()
                ->comment('テナントID');
            $table->string('email', 255)
                ->comment('配信先メールアドレス');
            $table->string('name', 100)->nullable()
                ->comment('表示名（任意）');
            $table->string('report_type', 50)->default('daily_sales')
                ->comment("レポート種別 (daily_sales / weekly / alert 等)");
            $table->boolean('is_active')->default(true)
                ->comment('false で配信停止');
            $table->timestamps();

            $table->unique(['tenant_id', 'email', 'report_type'], 'uniq_recipients_tenant_email_type');
            $table->index(['tenant_id', 'report_type', 'is_active'], 'idx_recipients_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_recipients');
    }
};
