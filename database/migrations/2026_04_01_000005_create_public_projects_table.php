<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 公開案件（マッチング用）
 *
 * SESマッチングプラットフォームに掲載する案件情報を管理する。
 * 既存の deals テーブルは契約管理用であり、マッチング前の公開案件はこのテーブルで管理する。
 * 成約後は applications.deal_id で既存の deals と紐付ける。
 *
 * posted_by_customer_id は既存の customers テーブルを参照する（掲載企業）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()
                ->comment('テナントID（多テナント分離用）');

            // 掲載者情報
            $table->unsignedBigInteger('posted_by_customer_id')->nullable()
                ->comment('掲載企業（customers テーブル参照）');
            $table->unsignedBigInteger('posted_by_user_id')
                ->comment('掲載した営業担当者（users テーブル参照）');

            // 案件概要
            $table->string('title', 200)->comment('案件タイトル');
            $table->text('description')->nullable()->comment('案件詳細説明');
            $table->string('end_client', 200)->nullable()
                ->comment('エンドクライアント名（任意・非開示の場合は空欄）');

            // 契約条件
            $table->decimal('unit_price_min', 10, 2)->nullable()
                ->comment('単価 下限（万円/月）');
            $table->decimal('unit_price_max', 10, 2)->nullable()
                ->comment('単価 上限（万円/月）');
            $table->string('contract_type', 20)->nullable()
                ->comment('契約形態: 準委任 / 派遣 / 請負');
            $table->integer('contract_period_months')->nullable()
                ->comment('契約期間（ヶ月）。長期想定の場合は null');
            $table->date('start_date')->nullable()
                ->comment('稼働開始予定日');

            // 精算条件（既存 ses_contracts と同じ構造）
            $table->decimal('deduction_hours', 6, 2)->nullable()
                ->comment('精算 下限時間');
            $table->decimal('overtime_hours', 6, 2)->nullable()
                ->comment('精算 上限時間');
            $table->smallInteger('settlement_unit_minutes')->nullable()
                ->comment('精算単位（分）: 15 / 30 等');

            // 勤務条件
            $table->string('work_location', 200)->nullable()
                ->comment('勤務地（住所 or エリア名）');
            $table->string('nearest_station', 100)->nullable()
                ->comment('最寄駅');
            $table->string('work_style', 20)->nullable()
                ->comment('勤務形態: remote / office / hybrid');
            $table->string('remote_frequency', 50)->nullable()
                ->comment('リモート頻度（週5在宅 / 月1出社 等の自由記述）');

            // 募集条件
            $table->integer('required_experience_years')->nullable()
                ->comment('必要経験年数（最低ライン）');
            $table->integer('team_size')->nullable()
                ->comment('チーム規模（人数）');
            $table->integer('interview_count')->nullable()
                ->comment('面談回数');
            $table->integer('headcount')->default(1)
                ->comment('募集人数');

            // ステータス・集計
            $table->string('status', 20)->default('open')
                ->comment('ステータス: open / closed / filled（充足）');
            $table->unsignedInteger('views_count')->default(0)
                ->comment('閲覧数（非正規化カウンタ）');
            $table->unsignedInteger('applications_count')->default(0)
                ->comment('応募数（非正規化カウンタ）');

            $table->timestamp('published_at')->nullable()->comment('公開日時');
            $table->timestamp('expires_at')->nullable()->comment('募集締切日時');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('posted_by_customer_id')
                ->references('id')
                ->on('customers')
                ->onDelete('set null');

            $table->foreign('posted_by_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->index(['tenant_id', 'status', 'published_at'],
                'idx_public_projects_tenant_status_published');
            $table->index(['tenant_id', 'start_date'],
                'idx_public_projects_tenant_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_projects');
    }
};
