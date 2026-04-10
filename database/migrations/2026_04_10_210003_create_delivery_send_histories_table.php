<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_send_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('campaign_id')->index();
            $table->unsignedBigInteger('delivery_address_id')->nullable()->index();
            $table->string('email', 300);
            $table->string('name', 200)->nullable();
            $table->string('status', 20)->default('sent')->comment('sent/failed/replied');
            $table->string('ses_message_id', 500)->nullable()->comment('SES Message-ID 返信紐づけ用');
            $table->text('error_message')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->unsignedBigInteger('reply_email_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('campaign_id')->references('id')->on('delivery_campaigns')->onDelete('cascade');
            $table->foreign('delivery_address_id')->references('id')->on('delivery_addresses')->onDelete('set null');
            $table->foreign('reply_email_id')->references('id')->on('emails')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_send_histories');
    }
};
