<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 応募情報
 *
 * 技術者が案件に応募した記録を管理する。
 * 選考ステータス・面談情報・成約時の契約連携まで一貫して管理する。
 *
 * 成約（status = 'accepted'）時は既存の deals テーブルにレコードを作成し、
 * deal_id でここから参照する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()
                ->comment('テナントID（多テナント分離用）');
            $table->unsignedBigInteger('project_id')
                ->comment('public_projects テーブルへの外部キー');
            $table->unsignedBigInteger('engineer_id')
                ->comment('engineers テーブルへの外部キー');
            $table->unsignedBigInteger('applied_by_user_id')
                ->comment('応募操作を行った営業担当者（users テーブル参照）');

            // 応募内容
            $table->text('message')->nullable()
                ->comment('応募メッセージ・アピールポイント');
            $table->string('resume_file_path', 500)->nullable()
                ->comment('応募時に添付した職務経歴書（Supabase Storage）');
            $table->decimal('proposed_unit_price', 10, 2)->nullable()
                ->comment('提案単価（万円/月）');

            // 選考ステータス
            $table->string('status', 30)->default('pending')
                ->comment(implode(' / ', [
                    'pending           : 応募済み（未確認）',
                    'reviewing          : 書類選考中',
                    'interview_scheduled: 面談設定済み',
                    'interviewed        : 面談済み',
                    'offer              : 内定',
                    'accepted           : 成約',
                    'rejected           : 不採用',
                    'withdrawn          : 辞退',
                ]));

            // 面談情報
            $table->timestamp('interview_date')->nullable()->comment('面談日時');
            $table->text('interview_memo')->nullable()->comment('面談メモ');

            // 成約時の契約連携（既存 deals テーブルのレコードを参照）
            $table->unsignedBigInteger('deal_id')->nullable()
                ->comment('成約時に生成した deals レコードへの外部キー');
            $table->decimal('commission_rate', 5, 2)->nullable()
                ->comment('手数料率（%）');
            $table->decimal('commission_amount', 10, 2)->nullable()
                ->comment('手数料額（万円）');

            $table->timestamp('reviewed_at')->nullable()->comment('書類確認日時');
            $table->timestamps();

            $table->unique(['project_id', 'engineer_id'],
                'uq_applications_project_engineer');

            $table->foreign('project_id')
                ->references('id')
                ->on('public_projects')
                ->onDelete('cascade');

            $table->foreign('engineer_id')
                ->references('id')
                ->on('engineers')
                ->onDelete('cascade');

            $table->foreign('applied_by_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('deal_id')
                ->references('id')
                ->on('deals')
                ->onDelete('set null');

            $table->index(['tenant_id', 'status'], 'idx_applications_tenant_status');
            $table->index(['tenant_id', 'engineer_id'], 'idx_applications_tenant_engineer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
