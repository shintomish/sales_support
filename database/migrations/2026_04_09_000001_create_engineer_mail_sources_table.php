<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 技術者メール業務エンジン
 *
 * emails テーブルから技術者紹介と判定されたメールを
 * 構造化して管理する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineer_mail_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('email_id')
                ->comment('元メール（emails.id）');

            // ── 判定結果 ──────────────────────────────────────
            $table->unsignedSmallInteger('score')->default(0)
                ->comment('技術者スコア（0〜100）');
            $table->json('score_reasons')->nullable()
                ->comment('判定理由の配列');
            $table->string('engine', 20)->default('rule')
                ->comment('判定エンジン: rule / ai');

            // ── 抽出情報（本文から） ──────────────────────────
            $table->string('name', 100)->nullable()
                ->comment('技術者氏名');
            $table->string('affiliation_type', 50)->nullable()
                ->comment('所属区分: 自社正社員/一社先正社員/BP/BP要員/契約社員/個人事業主/入社予定/採用予定');
            $table->string('available_from', 50)->nullable()
                ->comment('稼働開始日');
            $table->string('nearest_station', 100)->nullable()
                ->comment('最寄り駅');
            $table->json('skills')->nullable()
                ->comment('スキル一覧');
            $table->boolean('has_attachment')->default(false)
                ->comment('スキルシート添付あり');

            // ── ステータス管理 ────────────────────────────────
            $table->string('status', 30)->default('review')
                ->comment('review / new / registered / proposing / working / excluded');

            // ── メタ情報 ──────────────────────────────────────
            $table->timestamp('received_at')->nullable()
                ->comment('メール受信日時');

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
        Schema::dropIfExists('engineer_mail_sources');
    }
};
