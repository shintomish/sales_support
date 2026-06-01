<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 請求書送信履歴
 *  - メール / 郵送発送 双方の操作ログを記録（method で識別）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_send_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('invoice_id');
            $table->enum('method', ['mail', 'post'])->comment('送付方法');
            $table->json('to_emails')->nullable()->comment('TO アドレス（mail）');
            $table->json('cc_emails')->nullable()->comment('CC アドレス（mail）');
            $table->string('subject', 500)->nullable();
            $table->text('body')->nullable();
            $table->json('attachments_meta')->nullable()->comment('添付ファイル名一覧');
            $table->enum('status', ['sent', 'failed'])->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
            $table->index(['tenant_id', 'invoice_id'], 'idx_ish_tenant_invoice');
            $table->index('sent_at');
        });

        // 新規テーブルは RLS 有効化（Supabase 経由で外部公開されるのを防止）
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public.invoice_send_histories ENABLE ROW LEVEL SECURITY');
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('invoice_email_subject_template', 500)->nullable()->after('invoice_issuer_url');
            $table->text('invoice_email_body_template')->nullable()->after('invoice_email_subject_template');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_send_histories');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['invoice_email_subject_template', 'invoice_email_body_template']);
        });
    }
};
