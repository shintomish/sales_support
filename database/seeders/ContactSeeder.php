<?php

namespace Database\Seeders;

use App\Models\Contact;
use Illuminate\Database\Seeder;

class ContactSeeder extends Seeder
{
    public function run(): void
    {
        $contacts = [
            // サンプル商事
            [
                'customer_id' => 1,
                'name' => '佐藤太郎',
                'department' => '営業部',
                'position' => '部長',
                'email' => 'sato@sample-corp.example.com',
                'phone' => '03-1234-5678',
            ],
            [
                'customer_id' => 1,
                'name' => '鈴木花子',
                'department' => '購買部',
                'position' => '課長',
                'email' => 'suzuki@sample-corp.example.com',
                'phone' => '03-1234-5679',
            ],
            // テストシステムズ
            [
                'customer_id' => 2,
                'name' => '田中一郎',
                'department' => '情報システム部',
                'position' => '部長',
                'email' => 'tanaka@test-systems.example.com',
                'phone' => '03-2345-6789',
            ],
            // 山田商店
            [
                'customer_id' => 3,
                'name' => '山田次郎',
                'department' => null,
                'position' => '店長',
                'email' => 'yamada@yamada-shop.example.com',
                'phone' => '06-3456-7890',
            ],
        ];

        foreach ($contacts as $contact) {
            Contact::create($contact);
        }
    }
}