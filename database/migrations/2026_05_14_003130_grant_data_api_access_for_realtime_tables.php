<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supabase Data API ロール用に Realtime 購読対象テーブルへ明示的な GRANT を付与
 *
 * 2026-10-30 から既存 Supabase プロジェクトでも public スキーマのデフォルト権限が
 * 廃止されるため、Realtime 経由で読まれるテーブルに対し authenticated / service_role
 * への SELECT 権限を明示的に付与する。
 *
 * 対象テーブル (FE の supabase.channel(...).on('postgres_changes', { table: ... }) を grep して特定):
 *  - tasks, deals, activities, business_cards, emails
 *
 * 既存環境では PostgreSQL のデフォルト grant により読めている状態を、
 * 公式変更後も読み続けられるよう先回りで明示化する。
 */
return new class extends Migration
{
    private const REALTIME_TABLES = [
        'tasks',
        'deals',
        'activities',
        'business_cards',
        'emails',
    ];

    public function up(): void
    {
        foreach (self::REALTIME_TABLES as $table) {
            // authenticated: Realtime クライアント (フロント) が JWT で接続するロール
            DB::statement("GRANT SELECT ON public.{$table} TO authenticated");
            // service_role: Laravel バックエンドが service_role キーで接続するロール
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON public.{$table} TO service_role");
        }
    }

    public function down(): void
    {
        foreach (self::REALTIME_TABLES as $table) {
            DB::statement("REVOKE SELECT ON public.{$table} FROM authenticated");
            DB::statement("REVOKE SELECT, INSERT, UPDATE, DELETE ON public.{$table} FROM service_role");
        }
    }
};
