<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * dealsテーブルにSES案件固有のカラムを追加する
 *
 * 対応するExcel列:
 *   - deal_type        : 案件種別（SES / 一般）
 *   - project_number   : 項番 [col 0]
 *   - end_client       : エンド [col 9]
 *   - nearest_station  : 現場最寄駅 [col 38]
 *   - change_type      : 新・変更者・条件変更等 [col 2]
 *   - affiliation      : 所属 [col 4]
 *   - affiliation_contact : 所属担当者 [col 5]
 *   - invoice_number   : 適格請求書発行事業者登録番号(社員はTW状況) [col 46]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            // SES案件フラグ（既存のgeneralな商談と共存させる）
            $table->string('deal_type', 20)->default('general')->after('id')
                ->comment('案件種別: general / ses');

            // Excelの項番（インポート時の突合キー）
            $table->integer('project_number')->nullable()->after('deal_type')
                ->comment('Excel台帳の項番');

            // SES固有フィールド
            $table->string('end_client', 200)->nullable()->after('project_number')
                ->comment('エンドクライアント（顧客の先のクライアント）');

            $table->string('nearest_station', 100)->nullable()->after('end_client')
                ->comment('現場最寄駅');

            $table->string('change_type', 50)->nullable()->after('nearest_station')
                ->comment('新・変更者・条件変更等（新規/変更無/退場 等）');

            $table->string('affiliation', 100)->nullable()->after('change_type')
                ->comment('所属（社員/外注先会社名 等）');

            $table->string('affiliation_contact', 100)->nullable()->after('affiliation')
                ->comment('所属担当者');

            $table->text('invoice_number')->nullable()->after('affiliation_contact')
                ->comment('適格請求書発行事業者登録番号 または TW状況（社員の場合）');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn([
                'deal_type',
                'project_number',
                'end_client',
                'nearest_station',
                'change_type',
                'affiliation',
                'affiliation_contact',
                'invoice_number',
            ]);
        });
    }
};
