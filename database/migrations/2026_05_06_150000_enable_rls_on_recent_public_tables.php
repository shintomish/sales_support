<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 直近追加した 4 テーブルに RLS（Row Level Security）を有効化する（2026-05-06）。
 *
 * Supabase の advisor で rls_disabled_in_public が ERROR で検知された対応：
 *   - feedback_reports
 *   - invoices
 *   - invoice_lines
 *   - report_recipients
 *
 * Laravel は service_role キーで接続しており RLS をバイパスするため、
 * ENABLE のみで policy は作らない（default deny）。これにより PostgREST 経由の
 * 外部アクセス（anon/authenticated）は遮断される。
 */
return new class extends Migration
{
    private array $tables = [
        'feedback_reports',
        'invoices',
        'invoice_lines',
        'report_recipients',
    ];

    public function up(): void
    {
        foreach ($this->tables as $t) {
            DB::statement("ALTER TABLE public.{$t} ENABLE ROW LEVEL SECURITY");
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $t) {
            DB::statement("ALTER TABLE public.{$t} DISABLE ROW LEVEL SECURITY");
        }
    }
};
