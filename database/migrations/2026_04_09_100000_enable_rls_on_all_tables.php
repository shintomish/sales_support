<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * RLSを全テーブルに有効化する。
     * アプリは service_role キーで接続するため RLS をバイパスし、既存機能への影響はない。
     * anon / authenticated キーからの直接アクセスをブロックするセキュリティ対策。
     */
    public function up(): void
    {
        $tables = [
            'users',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'personal_access_tokens',
            'tenants',
            'customers',
            'contacts',
            'deals',
            'deal_assignees',
            'deal_import_logs',
            'activities',
            'tasks',
            'business_cards',
            'work_records',
            'ses_contracts',
            'pipeline_stages',
            'gmail_tokens',
            'emails',
            'email_attachments',
            'skills',
            'engineers',
            'engineer_profiles',
            'engineer_skills',
            'engineer_mail_sources',
            'project_required_skills',
            'public_projects',
            'project_views',
            'project_mail_sources',
            'matching_scores',
            'messages',
            'favorite_projects',
            'applications',
        ];

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'users',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'personal_access_tokens',
            'tenants',
            'customers',
            'contacts',
            'deals',
            'deal_assignees',
            'deal_import_logs',
            'activities',
            'tasks',
            'business_cards',
            'work_records',
            'ses_contracts',
            'pipeline_stages',
            'gmail_tokens',
            'emails',
            'email_attachments',
            'skills',
            'engineers',
            'engineer_profiles',
            'engineer_skills',
            'engineer_mail_sources',
            'project_required_skills',
            'public_projects',
            'project_views',
            'project_mail_sources',
            'matching_scores',
            'messages',
            'favorite_projects',
            'applications',
        ];

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE \"{$table}\" DISABLE ROW LEVEL SECURITY");
        }
    }
};
