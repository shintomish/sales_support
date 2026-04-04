<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SESマッチングに必要なフィールドを engineers / engineer_profiles に追加。
 *
 * engineers:       年齢・国籍・所属区分
 * engineer_profiles: 稼働ステータス・実績社数
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engineers', function (Blueprint $table) {
            $table->unsignedSmallInteger('age')->nullable()
                ->comment('年齢')
                ->after('affiliation_contact');
            $table->string('nationality', 100)->nullable()
                ->comment('国籍（例: 日本, 中国）')
                ->after('age');
            $table->string('affiliation_type', 20)->nullable()
                ->comment('所属区分: self=自社, bp=BP')
                ->after('nationality');
        });

        Schema::table('engineer_profiles', function (Blueprint $table) {
            $table->string('availability_status', 20)->default('available')
                ->comment('稼働状況: available=空き, working=稼働中, scheduled=◯月予定')
                ->after('available_from');
            $table->unsignedSmallInteger('past_client_count')->nullable()
                ->comment('稼働実績社数')
                ->after('availability_status');
        });
    }

    public function down(): void
    {
        Schema::table('engineers', function (Blueprint $table) {
            $table->dropColumn(['age', 'nationality', 'affiliation_type']);
        });

        Schema::table('engineer_profiles', function (Blueprint $table) {
            $table->dropColumn(['availability_status', 'past_client_count']);
        });
    }
};
