<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 汎用お気に入り（技術者/案件・メール由来/登録 を横断）。
 *   target_type: project_mail / public_project / engineer_mail / engineer
 *   target_id  : 各テーブルの主キー（ポリモーフィックのため FK は張らない）
 * /mail-search 等で ★ 登録 → 「お気に入り」から再提案する用途。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('target_type', 30); // project_mail/public_project/engineer_mail/engineer
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->unique(['user_id', 'target_type', 'target_id'], 'uq_favorites_user_target');
            $table->index(['user_id', 'target_type']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // --- Supabase RLS / GRANT (CLAUDE.md 強制)。sqlite テストではスキップ ---
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE public.favorites ENABLE ROW LEVEL SECURITY');
        // Laravel API(service_role) 経由のみ。supabase-js 直読み/Realtime は使わないため authenticated GRANT 不要。
        if (DB::selectOne("SELECT 1 AS x FROM pg_roles WHERE rolname = 'service_role'")) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON public.favorites TO service_role');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
