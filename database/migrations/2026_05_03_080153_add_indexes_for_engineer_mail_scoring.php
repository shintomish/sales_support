<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 技術者メールスコアリングの遅いクエリを高速化するインデックス追加
 *
 * Sentry レポートで頻発していた遅延クエリ:
 *   SELECT * FROM emails
 *   WHERE category = ? AND NOT EXISTS (
 *     SELECT 1 FROM engineer_mail_sources WHERE email_id = emails.id
 *   )
 *   ORDER BY received_at DESC LIMIT 100
 *
 * 対策:
 * 1. emails(tenant_id, category, received_at) - WHERE + ORDER BY を1スキャンで処理
 * 2. engineer_mail_sources(email_id) - NOT EXISTS のサブクエリ高速化
 *    （FK制約は外部キーカラムにインデックスを自動生成しないため）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            // 既存の (tenant_id, category) を拡張する形。
            // PostgreSQL は (tenant_id, category) を WHERE で使い、
            // (tenant_id, category, received_at) があれば ORDER BY も同じインデックスで処理可。
            $table->index(['tenant_id', 'category', 'received_at'], 'emails_tenant_category_received_at_index');
        });

        Schema::table('engineer_mail_sources', function (Blueprint $table) {
            $table->index('email_id', 'engineer_mail_sources_email_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex('emails_tenant_category_received_at_index');
        });

        Schema::table('engineer_mail_sources', function (Blueprint $table) {
            $table->dropIndex('engineer_mail_sources_email_id_index');
        });
    }
};
