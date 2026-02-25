<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('contact_id')->nullable()->constrained()->onDelete('set null');

            // OCRで抽出された生データ
            $table->text('ocr_text')->nullable();

            // 構造化されたデータ
            $table->string('company_name')->nullable();
            $table->string('person_name')->nullable();
            $table->string('department')->nullable();
            $table->string('position')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('fax')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // 画像保存パス
            $table->string('image_path')->nullable();

            // ステータス
            $table->enum('status', ['pending', 'processed', 'registered', 'failed'])->default('pending');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_cards');
    }
};
