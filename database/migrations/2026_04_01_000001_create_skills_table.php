<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * スキルマスタ
 *
 * Java / Python / AWS 等のスキル名をマスタとして管理する。
 * engineer_skills・project_required_skills から参照される。
 * テナント共通（tenant_id なし）のグローバルマスタとして運用する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique()->comment('スキル名（Java / Python / AWS 等）');
            $table->string('category', 50)->nullable()
                ->comment('カテゴリ: language / framework / database / infrastructure / other');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};
