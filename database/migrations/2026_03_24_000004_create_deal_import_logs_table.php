<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * deal_import_logsテーブル新規作成
 *
 * ExcelインポートAPIの実行履歴と結果を記録する。
 * - どのファイルをインポートしたか
 * - 何件成功・スキップ・エラーだったか
 * - エラー詳細（行番号・理由）
 *
 * 用途:
 *   - 重複インポート防止のチェック
 *   - インポート失敗時の原因特定
 *   - 管理画面でのインポート履歴表示
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_import_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()
                ->comment('テナントID');
            $table->unsignedBigInteger('imported_by')
                ->comment('インポートを実行したユーザーID');

            // ── ファイル情報 ─────────────────────────
            $table->string('original_filename', 255)
                ->comment('アップロードされたファイルの元のファイル名');
            $table->string('file_type', 20)->default('xlsm')
                ->comment('ファイル種別: xlsm / xlsx / csv');

            // ── インポート結果サマリー ────────────────
            $table->integer('total_rows')->default(0)
                ->comment('Excelの総データ行数（削除フラグ除く）');
            $table->integer('created_count')->default(0)
                ->comment('新規作成件数');
            $table->integer('updated_count')->default(0)
                ->comment('更新件数（既存レコードを上書き）');
            $table->integer('skipped_count')->default(0)
                ->comment('スキップ件数（重複・変更なし）');
            $table->integer('error_count')->default(0)
                ->comment('エラー件数');

            // ── エラー詳細（JSON配列）────────────────
            // 例: [{"row": 5, "project_number": 1002, "reason": "顧客名が空です"}]
            $table->json('error_details')->nullable()
                ->comment('エラー行の詳細情報（行番号・理由等）');

            // ── ステータス ───────────────────────────
            $table->string('status', 20)->default('processing')
                ->comment('インポート状態: processing / completed / failed');

            $table->timestamp('started_at')->nullable()
                ->comment('インポート開始日時');
            $table->timestamp('completed_at')->nullable()
                ->comment('インポート完了日時');

            $table->timestamps();

            $table->index(['tenant_id', 'created_at'], 'idx_import_logs_tenant_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_import_logs');
    }
};
