<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // 分類フラグ（仕入先/売上先、両方もあり）
            $table->boolean('is_supplier')->default(false)->after('tenant_id')->comment('仕入先');
            $table->boolean('is_customer')->default(true)->after('is_supplier')->comment('売上先');

            // SES台帳関連
            $table->string('invoice_number', 20)->nullable()->after('website')->comment('適格請求書登録番号');
            $table->string('fax', 20)->nullable()->after('phone');
            $table->integer('payment_site')->nullable()->after('invoice_number')->comment('入金サイト既定値（売上先用、日）');
            $table->integer('vendor_payment_site')->nullable()->after('payment_site')->comment('支払サイト既定値（仕入先用、日）');

            // 主担当者
            $table->unsignedBigInteger('primary_contact_id')->nullable()->after('vendor_payment_site')->comment('主担当者(contacts.id)');
            $table->foreign('primary_contact_id')->references('id')->on('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['primary_contact_id']);
            $table->dropColumn([
                'is_supplier',
                'is_customer',
                'invoice_number',
                'fax',
                'payment_site',
                'vendor_payment_site',
                'primary_contact_id',
            ]);
        });
    }
};
