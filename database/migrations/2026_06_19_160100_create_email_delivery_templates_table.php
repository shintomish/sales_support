<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 配信テンプレライブラリ (email_delivery_templates)。
 *
 * 2026-06-19 営業会議 §4 / AItem3「配信用とリアル案件用でテンプレを明確化」対応。
 *
 * 既存 email_body_templates は unique(tenant_id, user_id) の「1ユーザー=1署名プロファイル」
 * (氏名/部署/役職/sender_display_name) であり、value()/updateOrCreate が 1行前提で参照している。
 * そこへ目的別の複数テンプレを混載すると既存の From 表示名取得が壊れるため、別テーブルを新設する。
 *
 * 共有単位: テナント共有(全営業で共用)。一斉配信は会社共通文面の性質に合うため user_id は
 * 「作成者の記録」用途のみで絞り込みには使わない。
 *
 * purpose は delivery_campaigns.delivery_purpose と同じ語彙(standard / real_spot / ...)。
 * 中立性: テンプレは表記/文面のためで、score 順マッチングや優遇には一切関与させない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_delivery_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->comment('作成者(記録用・絞り込みには未使用)');
            $table->string('purpose', 20)->default('standard')->comment('standard / real_spot / existing_customer');
            $table->string('name', 100)->comment('テンプレ表示名 (例: 6月配信用)');
            $table->string('subject', 500)->nullable();
            $table->text('body_text')->nullable()->comment('本文。<%Name%> 等のプレースホルダ可');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'purpose', 'is_active']);
        });

        // --- Supabase RLS / GRANT (CLAUDE.md 強制)。sqlite テストではスキップ ---
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // PostgREST/Data API 経由の外部公開を防ぐ。policy は作らず default deny。
        DB::statement('ALTER TABLE public.email_delivery_templates ENABLE ROW LEVEL SECURITY');

        // 本テーブルは Laravel API(service_role) 経由でのみアクセスする想定
        // (既存 email_body_templates と同方式)。supabase-js 直読み/Realtime は使わないため
        // authenticated への GRANT は付けない(必要になれば SELECT を追加)。
        // test-postgres は service_role を持たないためガード。
        if (DB::selectOne("SELECT 1 AS x FROM pg_roles WHERE rolname = 'service_role'")) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON public.email_delivery_templates TO service_role');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_delivery_templates');
    }
};
