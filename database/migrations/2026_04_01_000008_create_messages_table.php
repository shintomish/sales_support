<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * メッセージング
 *
 * 応募に紐付く企業⇔技術者（営業担当）間のメッセージを管理する。
 * application_id 単位でスレッドを形成する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()
                ->comment('テナントID（多テナント分離用）');
            $table->unsignedBigInteger('application_id')
                ->comment('applications テーブルへの外部キー（スレッド単位）');
            $table->unsignedBigInteger('sender_user_id')
                ->comment('送信者（users テーブル参照）');
            $table->unsignedBigInteger('receiver_user_id')
                ->comment('受信者（users テーブル参照）');

            $table->text('content')->nullable()->comment('メッセージ本文');
            $table->string('file_path', 500)->nullable()
                ->comment('添付ファイルパス（Supabase Storage）');

            $table->boolean('is_read')->default(false)->comment('既読フラグ');
            $table->timestamp('read_at')->nullable()->comment('既読日時');

            $table->timestamps();

            $table->foreign('application_id')
                ->references('id')
                ->on('applications')
                ->onDelete('cascade');

            $table->foreign('sender_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('receiver_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->index(['application_id', 'created_at'],
                'idx_messages_application_created');
            $table->index(['receiver_user_id', 'is_read'],
                'idx_messages_receiver_unread');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
