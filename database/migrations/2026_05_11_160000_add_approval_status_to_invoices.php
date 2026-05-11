<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 請求書の承認ワークフロー強化（2026-05-11）
 *
 *  approval_status enum を追加し、申請→承認/却下の状態遷移を表現する。
 *  既存 approved (boolean) は冗長になるが互換性のため残す
 *  （approval_status = 'approved' のときに true）。
 *
 *  状態遷移:
 *    draft → pending → approved
 *                   ↘ rejected → (再申請して) pending
 *
 *  approval_comment は却下理由などのメモ用（任意）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // PostgreSQL では enum を使うと alter が面倒なので varchar + check 制約相当はアプリ側で担保
            $table->string('approval_status', 20)->default('draft')->after('approved_by')
                ->comment('承認状態: draft/pending/approved/rejected');
            $table->text('approval_comment')->nullable()->after('approval_status')
                ->comment('却下理由など承認に関するメモ');
        });

        // 既存データのバックフィル
        //   approved=true  → approval_status='approved'
        //   approved=false → approval_status='draft' (デフォルト値で既にセット済み)
        DB::statement("UPDATE invoices SET approval_status = 'approved' WHERE approved = TRUE");
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'approval_comment']);
        });
    }
};
