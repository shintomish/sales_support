<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('gmail_message_id')->unique(); // Gmail APIのメッセージID
            $table->string('thread_id')->nullable();
            $table->string('subject')->nullable();
            $table->string('from_address');
            $table->string('from_name')->nullable();
            $table->string('to_address');
            $table->text('body_text')->nullable();       // プレーンテキスト
            $table->text('body_html')->nullable();       // HTML本文
            $table->timestamp('received_at')->nullable();
            $table->boolean('is_read')->default(false);

            // 紐付け（将来のマッチング用）
            $table->foreignId('contact_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('deal_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');

            $table->timestamps();
            $table->index(['tenant_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
