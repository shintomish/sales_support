<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * PipelineStageSeeder
 *
 * パイプラインの初期ステージを全テナントに投入する。
 * tenant_id = 0 をシステムデフォルトとし、
 * テナントがカスタマイズしたい場合は自テナントIDでレコードを追加する。
 *
 * 実行コマンド:
 *   docker compose exec app php artisan db:seed --class=PipelineStageSeeder
 */
class PipelineStageSeeder extends Seeder
{
    public function run(): void
    {
        // システム共通デフォルト（tenant_id = 0）
        $stages = [
            [
                'key'              => 'active',
                'label'            => '稼働中',
                'color'            => '#10B981', // emerald-500
                'sort_order'       => 1,
                'is_active'        => true,
                'count_as_revenue' => true,
                'ses_only'         => true,
            ],
            [
                'key'              => 'renewal',
                'label'            => '更新交渉中',
                'color'            => '#F59E0B', // amber-500
                'sort_order'       => 2,
                'is_active'        => true,
                'count_as_revenue' => true,
                'ses_only'         => true,
            ],
            [
                'key'              => 'proposing',
                'label'            => '新規提案中',
                'color'            => '#3B82F6', // blue-500
                'sort_order'       => 3,
                'is_active'        => true,
                'count_as_revenue' => false,
                'ses_only'         => false,
            ],
            [
                'key'              => 'quoted',
                'label'            => '見積提出',
                'color'            => '#8B5CF6', // violet-500
                'sort_order'       => 4,
                'is_active'        => true,
                'count_as_revenue' => false,
                'ses_only'         => false,
            ],
            [
                'key'              => 'ordered',
                'label'            => '受注',
                'color'            => '#059669', // emerald-600
                'sort_order'       => 5,
                'is_active'        => true,
                'count_as_revenue' => true,
                'ses_only'         => false,
            ],
            [
                'key'              => 'expired',
                'label'            => '期限切れ',
                'color'            => '#6B7280', // gray-500
                'sort_order'       => 6,
                'is_active'        => true,
                'count_as_revenue' => false,
                'ses_only'         => true,
            ],
            [
                'key'              => 'lost',
                'label'            => '失注',
                'color'            => '#EF4444', // red-500
                'sort_order'       => 7,
                'is_active'        => true,
                'count_as_revenue' => false,
                'ses_only'         => false,
            ],
        ];

        foreach ($stages as $stage) {
            DB::table('pipeline_stages')->updateOrInsert(
                ['tenant_id' => 0, 'key' => $stage['key']],
                array_merge($stage, [
                    'tenant_id'  => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        $this->command->info('PipelineStageSeeder: ' . count($stages) . '件のデフォルトステージを投入しました。');
    }
}
