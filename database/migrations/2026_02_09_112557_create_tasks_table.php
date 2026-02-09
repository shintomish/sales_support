<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->comment('担当者ID');
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('cascade')->comment('顧客ID');
            $table->foreignId('deal_id')->nullable()->constrained()->onDelete('cascade')->comment('商談ID');
            $table->string('title')->comment('タイトル');
            $table->text('description')->nullable()->comment('説明');
            $table->date('due_date')->nullable()->comment('期限');
            $table->enum('status', ['未着手', '進行中', '完了'])->default('未着手')->comment('ステータス');
            $table->enum('priority', ['高', '中', '低'])->default('中')->comment('優先度');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};