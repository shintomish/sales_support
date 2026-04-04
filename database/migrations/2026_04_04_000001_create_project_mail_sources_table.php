<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 案件メール業務エンジン
 *
 * emails テーブル（保管庫）から案件と判定されたメールを
 * 構造化して管理する。
 * ルールベース判定（初期）→ AI判定（後続フェーズ）に
 * 差し替え可能なよう engine カラムで区別する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_mail_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('email_id')
                ->comment('元メール（emails.id）');

            // ── 判定結果 ──────────────────────────────────────
            $table->unsignedSmallInteger('score')->default(0)
                ->comment('案件スコア（0〜100）');
            $table->json('score_reasons')->nullable()
                ->comment('判定理由の配列 ["Java matched", ...]');
            $table->string('engine', 20)->default('rule')
                ->comment('判定エンジン: rule / ai');

            // ── 案件基本情報（抽出値） ────────────────────────
            $table->string('customer_name', 200)->nullable()
                ->comment('顧客会社名');
            $table->string('title', 300)->nullable()
                ->comment('案件タイトル（件名＋本文要約）');
            $table->json('required_skills')->nullable()
                ->comment('必須スキル ["Java", "Spring", ...]');
            $table->json('preferred_skills')->nullable()
                ->comment('尚可スキル');
            $table->json('process')->nullable()
                ->comment('工程 ["要件定義", "基本設計", ...]');
            $table->string('work_location', 200)->nullable()
                ->comment('勤務地・最寄駅');
            $table->boolean('remote_ok')->nullable()
                ->comment('リモート可否');
            $table->decimal('unit_price_min', 8, 2)->nullable()
                ->comment('単価下限（万円）');
            $table->decimal('unit_price_max', 8, 2)->nullable()
                ->comment('単価上限（万円）');
            $table->string('age_limit', 50)->nullable()
                ->comment('年齢制限（例: 〜45歳）');
            $table->boolean('nationality_ok')->nullable()
                ->comment('外国籍可否（true=可, false=不可, null=不明）');
            $table->string('contract_type', 50)->nullable()
                ->comment('契約形態: 準委任 / 派遣 / 請負');
            $table->string('start_date', 50)->nullable()
                ->comment('開始時期（例: 即日, 2026-05-01）');
            $table->unsignedTinyInteger('supply_chain')->nullable()
                ->comment('商流（何次請け: 1=一次, 2=二次, ...）');

            // ── ステータス管理 ────────────────────────────────
            $table->string('status', 30)->default('new')
                ->comment('new / matched / proposed / interview / won / lost');
            $table->text('lost_reason')->nullable()
                ->comment('失注理由（営業教育データ）');

            // ── メタ情報 ──────────────────────────────────────
            $table->timestamp('received_at')->nullable()
                ->comment('メール受信日時（SLA管理用）');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')
                ->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('email_id')
                ->references('id')->on('emails')->onDelete('cascade');

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'score']);
            $table->index(['tenant_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_mail_sources');
    }
};
