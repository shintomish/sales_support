<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BusinessCardSeeder extends Seeder
{
    /**
     * テスト用名刺データを4件投入する
     * 実行: php artisan db:seed --class=BusinessCardSeeder
     *
     * ※ business_cards テーブルの tenant_id は
     *   TestDataSeeder で作成したテナント1（izen-solution）を使用
     */
    public function run(): void
    {
        // tenantsテーブルの最初のレコードのIDを使用
        $tenantId = DB::table('tenants')->value('id') ?? 1;

        // そのテナントに属するユーザーIDを取得（user_id は NOT NULL のため必須）
        $userId = DB::table('users')->where('tenant_id', $tenantId)->value('id') ?? 1;

        $now = now();

        $cards = [
            [
                'tenant_id'    => $tenantId,
                'user_id'      => $userId,
                'customer_id'  => null,
                'contact_id'   => null,
                'company_name' => '株式会社山田製作所',
                'person_name'  => '山田 太郎',
                'department'   => '営業部',
                'position'     => '営業部長',
                'postal_code'  => '100-0005',
                'address'      => '東京都千代田区丸の内1-1-1',
                'phone'        => '03-1234-5678',
                'mobile'       => '090-1234-5678',
                'fax'          => '03-1234-5679',
                'email'        => 'yamada.taro@yamada-mfg.co.jp',
                'website'      => 'https://yamada-mfg.co.jp',
                'ocr_text'     => null,
                'status'       => 'registered',
                'image_path'   => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'tenant_id'    => $tenantId,
                'user_id'      => $userId,
                'customer_id'  => null,
                'contact_id'   => null,
                'company_name' => '東京ITソリューションズ',
                'person_name'  => '鈴木 花子',
                'department'   => 'システム開発部',
                'position'     => 'プロジェクトマネージャー',
                'postal_code'  => '150-0043',
                'address'      => '東京都渋谷区道玄坂2-10-12',
                'phone'        => '03-2345-6789',
                'mobile'       => '080-2345-6789',
                'fax'          => null,
                'email'        => 'suzuki.hanako@tokyo-it.co.jp',
                'website'      => 'https://tokyo-it.co.jp',
                'ocr_text'     => null,
                'status'       => 'processed',
                'image_path'   => null,
                'created_at'   => $now->copy()->subDays(3),
                'updated_at'   => $now->copy()->subDays(3),
            ],
            [
                'tenant_id'    => $tenantId,
                'user_id'      => $userId,
                'customer_id'  => null,
                'contact_id'   => null,
                'company_name' => 'サンライズ建設株式会社',
                'person_name'  => '佐藤 次郎',
                'department'   => '企画部',
                'position'     => '課長',
                'postal_code'  => '060-0001',
                'address'      => '北海道札幌市中央区北1条西2丁目',
                'phone'        => '011-678-9012',
                'mobile'       => null,
                'fax'          => '011-678-9013',
                'email'        => 'sato.jiro@sunrise-const.co.jp',
                'website'      => null,
                'ocr_text'     => null,
                'status'       => 'pending',
                'image_path'   => null,
                'created_at'   => $now->copy()->subDays(7),
                'updated_at'   => $now->copy()->subDays(7),
            ],
            [
                'tenant_id'    => $tenantId,
                'user_id'      => $userId,
                'customer_id'  => null,
                'contact_id'   => null,
                'company_name' => '株式会社グリーンエナジー',
                'person_name'  => '田中 美咲',
                'department'   => '事業開発部',
                'position'     => '事業開発マネージャー',
                'postal_code'  => '530-0001',
                'address'      => '大阪府大阪市北区梅田1-2-3',
                'phone'        => '06-3456-7890',
                'mobile'       => '070-3456-7890',
                'fax'          => null,
                'email'        => 'tanaka.misaki@green-energy.co.jp',
                'website'      => 'https://green-energy.co.jp',
                'ocr_text'     => null,
                'status'       => 'registered',
                'image_path'   => null,
                'created_at'   => $now->copy()->subDays(14),
                'updated_at'   => $now->copy()->subDays(14),
            ],
        ];

        DB::table('business_cards')->insert($cards);

        $this->command->info('✅ 名刺テストデータを4件投入しました。');
    }
}
