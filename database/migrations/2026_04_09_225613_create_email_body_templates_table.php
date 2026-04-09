<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_body_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('name', 100)->comment('①氏名');
            $table->string('name_en', 100)->nullable()->comment('②英字氏名');
            $table->string('department', 100)->nullable()->comment('③所属部署');
            $table->string('position', 100)->nullable()->comment('④役職');
            $table->string('email', 200)->nullable()->comment('⑤メールアドレス');
            $table->string('mobile', 50)->nullable()->comment('⑥携帯電話');
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_body_templates');
    }
};
