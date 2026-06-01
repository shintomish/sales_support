<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 要件 × 候補 の対照表生成結果 (docs/480 §3.2)。
 * PMS × EMS / PMS × Engineer の 2 系統を 1 表で管理 (engineer_mail_source_id / engineer_id どちらか non-null)。
 *
 * - tenant_id + project_mail_source_id + engineer_mail_source_id でユニーク (PMS×EMS マッチ)
 * - tenant_id + project_mail_source_id + engineer_id でユニーク (PMS×登録済技術者)
 * - requirements_json は Stage 1 結果のスナップショット (PMS の ai_requirements 更新後も履歴を保つ)
 * - matches_json は Stage 2 結果 + 営業手動上書きを反映
 *
 * RLS: フロントは authenticated 経由で読まないため authenticated への GRANT は不要。
 *      Laravel (service_role) のみ操作する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requirement_match_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_mail_source_id')->constrained()->onDelete('cascade');
            $table->foreignId('engineer_mail_source_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('engineer_id')->nullable()->constrained()->onDelete('cascade');
            $table->jsonb('requirements_json');
            $table->jsonb('matches_json');
            $table->string('model', 50);
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('cache_read_tokens')->nullable();
            $table->integer('cache_write_tokens')->nullable();
            $table->timestamp('generated_at');
            $table->foreignId('edited_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'project_mail_source_id']);
            $table->index(['tenant_id', 'engineer_mail_source_id']);
            $table->index(['tenant_id', 'engineer_id']);
        });

        // 以下は Pgsql 固有 (partial index / CHECK / RLS / GRANT)。sqlite テストではスキップ
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // engineer_mail_source_id / engineer_id のどちらかでユニーク制約 (片方は NULL のため部分 index)
        DB::statement('CREATE UNIQUE INDEX requirement_match_results_pms_ems_unique '
            . 'ON public.requirement_match_results (tenant_id, project_mail_source_id, engineer_mail_source_id) '
            . 'WHERE engineer_mail_source_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX requirement_match_results_pms_engineer_unique '
            . 'ON public.requirement_match_results (tenant_id, project_mail_source_id, engineer_id) '
            . 'WHERE engineer_id IS NOT NULL AND deleted_at IS NULL');

        // どちらか一方が non-null である制約
        DB::statement('ALTER TABLE public.requirement_match_results '
            . 'ADD CONSTRAINT requirement_match_results_engineer_or_ems_check '
            . 'CHECK ((engineer_mail_source_id IS NOT NULL AND engineer_id IS NULL) '
            . 'OR (engineer_mail_source_id IS NULL AND engineer_id IS NOT NULL))');

        // RLS / GRANT (CLAUDE.md ルール準拠)
        DB::statement('ALTER TABLE public.requirement_match_results ENABLE ROW LEVEL SECURITY');
        // test-postgres は service_role を持たないためガード
        if (DB::selectOne("SELECT 1 AS x FROM pg_roles WHERE rolname = 'service_role'")) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON public.requirement_match_results TO service_role');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('requirement_match_results');
    }
};
