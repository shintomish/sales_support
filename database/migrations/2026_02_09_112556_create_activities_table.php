<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade')->comment('顧客ID');
            $table->foreignId('deal_id')->nullable()->constrained()->onDelete('cascade')->comment('商談ID');
            $table->foreignId('contact_id')->nullable()->constrained()->onDelete('set null')->comment('担当者ID');
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->comment('営業担当者ID');
            $table->enum('type', ['訪問', '電話', 'メール', 'その他'])->comment('活動種別');
            $table->string('subject')->comment('件名');
            $table->text('content')->nullable()->comment('内容');
            $table->date('activity_date')->comment('活動日');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};