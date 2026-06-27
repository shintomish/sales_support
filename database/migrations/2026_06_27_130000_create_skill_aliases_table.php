<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * スキル同義語辞書（名寄せ）。
 *   canonical: 正規名（グループ代表。表示・スコア等価判定の基準）
 *   alias    : 表記揺れ・別名・略称（canonical 自身も1行含める）
 * 例: canonical=Java に対し alias=Java / JAVA / ジャバ。
 *
 * 方針（read-time 正規化）:
 *   - 既存メールの再抽出・再スコアはしない。比較時に名寄せして検索/今後のスコア照合を改善する。
 *   - グローバルマスタ（skills 同様 tenant 非依存）。技術名・職種名は全テナント共通のため。
 *   - Angular↔AngularJS のような「別物」は alias にしない（誤マッチ防止）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('canonical', 80);          // 正規名（グループ代表）
            $table->string('alias', 80)->unique();     // 表記揺れ・別名（一意）
            $table->timestamps();
            $table->index('canonical');
        });

        // 初期辞書（SES頻出のみ厳選）。canonical => [表記揺れ（canonical 自身も含む）]
        $groups = [
            // ── 技術（大小/記号/カナ/略称/バージョンの揺れ） ──
            'Java'           => ['Java', 'JAVA', 'ジャバ'],
            'JavaScript'     => ['JavaScript', 'JS', 'ジャバスクリプト'],
            'TypeScript'     => ['TypeScript', 'TS', 'タイプスクリプト'],
            'Python'         => ['Python', 'パイソン'],
            'React'          => ['React', 'React.js', 'ReactJS', 'リアクト'],
            'Vue.js'         => ['Vue.js', 'Vue', 'VueJS'],
            'Node.js'        => ['Node.js', 'NodeJS'],
            'C#'             => ['C#', 'CSharp'],
            '.NET'           => ['.NET', 'dotNET', 'ドットネット'],
            'Ruby on Rails'  => ['Ruby on Rails', 'Rails', 'RoR'],
            'PostgreSQL'     => ['PostgreSQL', 'Postgres', 'ポスグレ'],
            'SQL Server'     => ['SQL Server', 'SQLServer', 'MSSQL'],
            'Kubernetes'     => ['Kubernetes', 'k8s'],
            'AWS'            => ['AWS', 'Amazon Web Services'],
            'GCP'            => ['GCP', 'Google Cloud', 'Google Cloud Platform'],
            // ── 職種・役割（日本語同義語） ──
            '情報システム'    => ['情報システム', '情シス', '社内SE', '社内情シス', '社内システム'],
            'プロジェクトマネージャー' => ['プロジェクトマネージャー', 'プロジェクトマネージャ', 'プロマネ', 'PM'],
            'システムエンジニア' => ['システムエンジニア', 'SE'],
            'ネットワーク'    => ['ネットワーク', 'NW'],
        ];

        $now  = now();
        $rows = [];
        foreach ($groups as $canonical => $aliases) {
            foreach (array_unique($aliases) as $alias) {
                $rows[] = ['canonical' => $canonical, 'alias' => $alias, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        // alias は unique。再実行されない migration なので素直に insert。
        DB::table('skill_aliases')->insert($rows);

        // --- Supabase RLS / GRANT (CLAUDE.md 強制)。sqlite テストではスキップ ---
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE public.skill_aliases ENABLE ROW LEVEL SECURITY');
        // 将来の管理UIで supabase-js 直読みする可能性に備え authenticated に SELECT を付与。
        if (DB::selectOne("SELECT 1 AS x FROM pg_roles WHERE rolname = 'authenticated'")) {
            DB::statement('GRANT SELECT ON public.skill_aliases TO authenticated');
        }
        if (DB::selectOne("SELECT 1 AS x FROM pg_roles WHERE rolname = 'service_role'")) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON public.skill_aliases TO service_role');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_aliases');
    }
};
