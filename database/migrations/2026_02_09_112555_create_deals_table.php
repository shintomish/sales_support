<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade')->comment('顧客ID');
            $table->foreignId('contact_id')->nullable()->constrained()->onDelete('set null')->comment('担当者ID');
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->comment('営業担当者ID');
            $table->string('title')->comment('商談名');
            $table->decimal('amount', 12, 2)->default(0)->comment('予定金額');
            $table->enum('status', ['新規', '提案', '交渉', '成約', '失注'])->default('新規')->comment('ステータス');
            $table->integer('probability')->default(0)->comment('成約確度(%)');
            $table->date('expected_close_date')->nullable()->comment('予定成約日');
            $table->date('actual_close_date')->nullable()->comment('実際の成約日');
            $table->text('notes')->nullable()->comment('備考');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};