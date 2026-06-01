<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rescore-all 非同期化のための進捗管理テーブル (docs #4)。
 *
 * フロントの POST /rescore-all は同期バッチをやめ、本テーブルに pending 行を作って即返す。
 * 毎分の Schedule::call('rescore-jobs-tick') が時間ボックス内でバッチ処理し進捗を更新、
 * フロントは status エンドポイントをポーリングする。
 *
 * - type: project_mail / engineer_mail (両 rescore-all を 1 表で管理)
 * - status: pending → processing → completed / failed
 * - cursor_offset: 次バッチの offset。total_count に達したら完了
 *
 * RLS: フロントは Laravel API 経由で参照する (supabase-js 直読みしない) ため
 *      authenticated への GRANT は不要。Laravel(service_role) のみ操作。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rescore_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('type', 20);                          // project_mail / engineer_mail
            $table->string('status', 20)->default('pending');    // pending/processing/completed/failed
            $table->integer('total_count')->default(0);
            $table->integer('processed_count')->default(0);
            $table->integer('cursor_offset')->default(0);
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'status']);
        });

        // RLS / GRANT (CLAUDE.md ルール準拠)
        DB::statement('ALTER TABLE public.rescore_jobs ENABLE ROW LEVEL SECURITY');
        // test-postgres は service_role を持たないためガード
        if (DB::selectOne("SELECT 1 AS x FROM pg_roles WHERE rolname = 'service_role'")) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON public.rescore_jobs TO service_role');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rescore_jobs');
    }
};
