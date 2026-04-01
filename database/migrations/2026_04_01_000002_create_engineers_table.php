<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 技術者マスタ
 *
 * SES技術者の基本情報を管理する。
 * 既存の deals テーブルは技術者のアサイン/契約を表すが、
 * 技術者自体のマスタとしてこのテーブルを新設する。
 *
 * マッチングで成約した場合は deals レコードを生成し、
 * applications.deal_id で関連付ける。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()
                ->comment('テナントID（多テナント分離用）');

            $table->string('name', 100)->comment('技術者氏名');
            $table->string('name_kana', 100)->nullable()->comment('氏名カナ');
            $table->string('email', 200)->nullable()->comment('メールアドレス');
            $table->string('phone', 50)->nullable()->comment('電話番号');

            // 所属情報（既存 deals.affiliation と対応）
            $table->string('affiliation', 100)->nullable()
                ->comment('所属会社名（外注先 or 社員）');
            $table->string('affiliation_contact', 100)->nullable()
                ->comment('所属先の担当者名');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineers');
    }
};
