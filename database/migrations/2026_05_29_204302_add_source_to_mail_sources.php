<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_mail_sources / engineer_mail_sources に source 区別カラムを追加。
 *
 * - 'imap'   : 既存の Kagoya IMAP 取り込みフロー (デフォルト)
 * - 'manual' : ユーザーが /project-mails/manual / /engineer-mails/manual から手動登録
 *
 * 一覧 API のフィルタで「通常メール」「手動登録」を別枠に分離するための識別子。
 * 既存レコードは default 'imap' のまま残る。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_mail_sources', function (Blueprint $table) {
            $table->string('source', 16)->default('imap')->after('engine')
                  ->comment('取り込み元: imap=Kagoya取込 / manual=手動登録');
            $table->index(['tenant_id', 'source']);
        });

        Schema::table('engineer_mail_sources', function (Blueprint $table) {
            $table->string('source', 16)->default('imap')->after('engine')
                  ->comment('取り込み元: imap=Kagoya取込 / manual=手動登録');
            $table->index(['tenant_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('project_mail_sources', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropColumn('source');
        });

        Schema::table('engineer_mail_sources', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropColumn('source');
        });
    }
};
