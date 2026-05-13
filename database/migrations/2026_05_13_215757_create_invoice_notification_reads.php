<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 承認系通知（承認されました / 差戻し）の既読状態を user 単位で記録
 *
 * バッジクリック等で消した時に「誰が・いつ・どのバッジを」既読にしたかの証跡を残す。
 * 7日経過で自動的に表示対象から外れる仕様は維持しつつ、ユーザー手動の既読化も可能にする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('user_id');
            $table->string('notification_type', 30)->comment('approved / rejected');
            $table->timestamp('read_at');
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['invoice_id', 'user_id', 'notification_type'], 'uniq_inr_invoice_user_type');
            $table->index(['user_id', 'notification_type'], 'idx_inr_user_type');
        });

        DB::statement('ALTER TABLE public.invoice_notification_reads ENABLE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_notification_reads');
    }
};
