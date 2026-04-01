<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 技術者プロフィール拡張
 *
 * engineers テーブルの 1:1 拡張。
 * マッチングに必要な希望条件・稼働情報・公開設定を管理する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineer_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()
                ->comment('テナントID（多テナント分離用）');
            $table->unsignedBigInteger('engineer_id')->unique()
                ->comment('engineers テーブルへの外部キー（1:1）');

            // 希望単価
            $table->decimal('desired_unit_price_min', 10, 2)->nullable()
                ->comment('希望単価 下限（万円/月）');
            $table->decimal('desired_unit_price_max', 10, 2)->nullable()
                ->comment('希望単価 上限（万円/月）');

            // 稼働情報
            $table->date('available_from')->nullable()
                ->comment('稼働可能開始日');
            $table->string('work_style', 20)->nullable()
                ->comment('希望勤務形態: remote / office / hybrid');
            $table->string('preferred_location', 100)->nullable()
                ->comment('希望勤務地（都道府県 or 市区町村）');

            // 自己PR・ドキュメント
            $table->text('self_introduction')->nullable()
                ->comment('自己PR・アピールポイント');
            $table->string('resume_file_path', 500)->nullable()
                ->comment('職務経歴書ファイルパス（Supabase Storage）');
            $table->string('github_url', 200)->nullable()->comment('GitHub URL');
            $table->string('portfolio_url', 200)->nullable()->comment('ポートフォリオ URL');

            // 公開設定
            $table->boolean('is_public')->default(false)
                ->comment('マッチング市場への公開フラグ');

            $table->timestamps();

            $table->foreign('engineer_id')
                ->references('id')
                ->on('engineers')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineer_profiles');
    }
};
