<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('email', 300);
            $table->string('name', 200)->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->string('prefecture', 50)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('tel', 50)->nullable();
            $table->string('occupation', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_addresses');
    }
};
