<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * /mail-search の AIマッチ判定キャッシュ。
 *   同じ検索意図(query_hash) × 同じ候補(target_type, target_id) の判定を保存し、再判定を避ける。
 *   verdict: ◯ / △ / ×。advisory(参考)用途のためテナント内キャッシュ。
 *
 * 注意: 候補データが後から変わると判定は陳腐化し得る（メール由来は実質不変）。
 *       必要なら将来「再判定」で上書きできるよう updated_at を持つ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_match_judgments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('query_hash', 64);          // sha256(正規化した検索意図)
            $table->string('target_type', 30);         // project_mail/public_project/engineer_mail/engineer
            $table->unsignedBigInteger('target_id');
            $table->string('verdict', 4);              // ◯ / △ / ×
            $table->string('reason', 120)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'query_hash', 'target_type', 'target_id'], 'uq_ai_match_judgment');
        });

        // --- Supabase RLS / GRANT (CLAUDE.md 強制)。sqlite テストではスキップ ---
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE public.ai_match_judgments ENABLE ROW LEVEL SECURITY');
        // Laravel API(service_role) 経由のみ。supabase-js 直読み/Realtime は使わないため authenticated GRANT 不要。
        if (DB::selectOne("SELECT 1 AS x FROM pg_roles WHERE rolname = 'service_role'")) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON public.ai_match_judgments TO service_role');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_match_judgments');
    }
};
