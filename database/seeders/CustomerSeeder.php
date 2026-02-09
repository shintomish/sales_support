<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            [
                'company_name' => '株式会社サンプル商事',
                'industry' => '製造業',
                'employee_count' => 500,
                'address' => '東京都千代田区丸の内1-1-1',
                'phone' => '03-1234-5678',
                'website' => 'https://sample-corp.example.com',
                'notes' => '大手製造業。年間取引額500万円',
            ],
            [
                'company_name' => 'テストシステムズ株式会社',
                'industry' => 'IT・通信',
                'employee_count' => 200,
                'address' => '東京都渋谷区渋谷2-2-2',
                'phone' => '03-2345-6789',
                'website' => 'https://test-systems.example.com',
                'notes' => 'システム開発会社。新規顧客',
            ],
            [
                'company_name' => '山田商店',
                'industry' => '小売業',
                'employee_count' => 50,
                'address' => '大阪府大阪市北区梅田3-3-3',
                'phone' => '06-3456-7890',
                'website' => null,
                'notes' => '中小企業。地域密着型',
            ],
        ];

        foreach ($customers as $customer) {
            Customer::create($customer);
        }
    }
}