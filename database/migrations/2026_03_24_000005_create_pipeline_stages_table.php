<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pipeline_stagesテーブル新規作成
 *
 * カンバン表示用のパイプラインステージ定義。
 * テナントごとにカスタマイズ可能にする。
 *
 * 初期データ（Seederで投入）:
 *   1. 稼働中     （SES: 現在契約中の案件）
 *   2. 更新交渉中 （SES: 契約更新を調整中）
 *   3. 新規提案中 （一般営業: 新規案件を提案中）
 *   4. 見積提出   （一般営業: 見積書を提出済み）
 *   5. 受注       （一般営業: 受注確定）
 *   6. 期限切れ   （SES: 契約終了済み）
 *   7. 失注       （一般営業: 失注）
 *
 * deals.status カラムとのマッピング:
 *   deals.status（既存の文字列）→ pipeline_stages.key で対応付ける
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()
                ->comment('テナントID（0=システム共通デフォルト）');

            // ステージの識別キー（コード値。dealsのstatusと対応）
            $table->string('key', 50)
                ->comment('ステージキー: active / renewal / proposing / quoted / ordered / expired / lost');

            // 表示名（テナントごとにカスタマイズ可）
            $table->string('label', 100)
                ->comment('表示名（例: 稼働中、更新交渉中）');

            // カンバンカードの色（Tailwindクラス or HEX）
            $table->string('color', 50)->default('#6B7280')
                ->comment('カードのアクセントカラー（HEX）');

            // 表示順
            $table->unsignedSmallInteger('sort_order')->default(0)
                ->comment('カンバン列の表示順（左から右へ昇順）');

            // このステージをパイプラインに表示するか
            $table->boolean('is_active')->default(true)
                ->comment('有効フラグ（falseで非表示）');

            // 売上予測の計算対象か（受注確度100%のステージは必ずtrue）
            $table->boolean('count_as_revenue')->default(false)
                ->comment('売上予測の計算対象フラグ');

            // SES専用ステージか（SES以外のdeal_typeには表示しない）
            $table->boolean('ses_only')->default(false)
                ->comment('SES専用ステージフラグ');

            $table->timestamps();

            $table->unique(['tenant_id', 'key'], 'uniq_pipeline_stages_tenant_key');
            $table->index(['tenant_id', 'sort_order'], 'idx_pipeline_stages_tenant_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_stages');
    }
};
