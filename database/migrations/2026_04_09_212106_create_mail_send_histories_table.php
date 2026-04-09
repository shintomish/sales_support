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
        Schema::create('mail_send_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('project_mail_id')->nullable()->index();
            $table->string('send_type', 20)->comment('proposal / bulk');
            $table->string('to_address', 300);
            $table->string('to_name', 200)->nullable();
            $table->string('subject', 500);
            $table->text('body');
            $table->string('status', 20)->default('sent')->comment('sent / failed');
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable()->comment('送信者user_id');
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('project_mail_id')->references('id')->on('project_mail_sources')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_send_histories');
    }
};
