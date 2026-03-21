<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

class TestDataSeeder extends Seeder
{
    private string $supabaseUrl;
    private string $serviceRoleKey;

    public function __construct()
    {
        $this->supabaseUrl    = config('services.supabase.url');
        $this->serviceRoleKey = config('services.supabase.service_role_key');
    }

    public function run(): void
    {
        // ===== Tenants (3社) =====
        $tenants = [
            ['name' => '株式会社アイゼン・ソリューション', 'slug' => 'izen-solution', 'plan' => 'pro',   'is_active' => 1],
            ['name' => '東和商事株式会社',                  'slug' => 'towa-shoji',    'plan' => 'basic', 'is_active' => 1],
            ['name' => '株式会社ネクストステージ',          'slug' => 'next-stage',    'plan' => 'basic', 'is_active' => 1],
        ];
        $tenantIds = [];
        foreach ($tenants as $tenant) {
            $tenantIds[] = DB::table('tenants')->insertGetId(array_merge($tenant, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }

        // ===== Users (10人) =====
        $users = [
            ['name' => '新冨 泰明',   'email' => 'shintomi.sh@gmail.com',        'role' => 'super_admin',  'tenant_id' => $tenantIds[0]],
            ['name' => '鈴木 健一',   'email' => 'suzuki.k@izen-solution.jp',    'role' => 'tenant_admin', 'tenant_id' => $tenantIds[0]],
            ['name' => '佐藤 美咲',   'email' => 'sato.m@izen-solution.jp',      'role' => 'tenant_user',  'tenant_id' => $tenantIds[0]],
            ['name' => '高橋 雄太',   'email' => 'takahashi.y@izen-solution.jp', 'role' => 'tenant_user',  'tenant_id' => $tenantIds[0]],
            ['name' => '伊藤 直樹',   'email' => 'ito.n@towa-shoji.co.jp',       'role' => 'tenant_admin', 'tenant_id' => $tenantIds[1]],
            ['name' => '渡辺 さくら', 'email' => 'watanabe.s@towa-shoji.co.jp',  'role' => 'tenant_user',  'tenant_id' => $tenantIds[1]],
            ['name' => '中村 隆',     'email' => 'nakamura.t@towa-shoji.co.jp',  'role' => 'tenant_user',  'tenant_id' => $tenantIds[1]],
            ['name' => '小林 朋子',   'email' => 'kobayashi.t@next-stage.jp',    'role' => 'tenant_admin', 'tenant_id' => $tenantIds[2]],
            ['name' => '加藤 誠',     'email' => 'kato.m@next-stage.jp',         'role' => 'tenant_user',  'tenant_id' => $tenantIds[2]],
            ['name' => '吉田 奈々',   'email' => 'yoshida.n@next-stage.jp',      'role' => 'tenant_user',  'tenant_id' => $tenantIds[2]],
        ];

        $userIds = [];
        foreach ($users as $user) {
            // Supabase Authにユーザー作成
            $supabaseUid = $this->createSupabaseUser($user['email'], 'password');

            $userIds[] = DB::table('users')->insertGetId(array_merge($user, [
                'password'     => Hash::make('password'),
                'supabase_uid' => $supabaseUid,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]));
        }

        // テナント別adminのuser_id
        $adminByTenant = [
            $tenantIds[0] => $userIds[1], // 鈴木 健一
            $tenantIds[1] => $userIds[4], // 伊藤 直樹
            $tenantIds[2] => $userIds[7], // 小林 朋子
        ];

        // ===== Customers (10社) =====
        $customers = [
            ['company_name' => '株式会社山田製作所',          'industry' => '製造業',      'phone' => '03-1234-5678', 'address' => '東京都千代田区丸の内1-1-1',          'employee_count' => 320, 'website' => 'https://yamada-mfg.co.jp',          'tenant_id' => $tenantIds[0]],
            ['company_name' => '東京ITソリューションズ',      'industry' => 'IT・通信',    'phone' => '03-2345-6789', 'address' => '東京都渋谷区道玄坂2-10-7',           'employee_count' => 85,  'website' => 'https://tokyo-its.co.jp',           'tenant_id' => $tenantIds[0]],
            ['company_name' => '株式会社グリーンエナジー',    'industry' => 'エネルギー',  'phone' => '06-3456-7890', 'address' => '大阪府大阪市北区梅田3-1-3',          'employee_count' => 150, 'website' => 'https://green-energy.co.jp',        'tenant_id' => $tenantIds[0]],
            ['company_name' => '株式会社テックイノベーション','industry' => 'IT・通信',    'phone' => '03-0123-4567', 'address' => '東京都新宿区西新宿2-8-1',            'employee_count' => 130, 'website' => 'https://tech-innovation.co.jp',     'tenant_id' => $tenantIds[0]],
            ['company_name' => '日本物流センター株式会社',    'industry' => '物流・運輸',  'phone' => '045-456-7890', 'address' => '神奈川県横浜市西区みなとみらい2-3-1', 'employee_count' => 520, 'website' => 'https://nihon-logistics.co.jp',     'tenant_id' => $tenantIds[1]],
            ['company_name' => '株式会社フードプラネット',    'industry' => '食品・飲料',  'phone' => '052-567-8901', 'address' => '愛知県名古屋市中区栄3-15-33',        'employee_count' => 210, 'website' => 'https://food-planet.co.jp',         'tenant_id' => $tenantIds[1]],
            ['company_name' => 'サンライズ建設株式会社',      'industry' => '建設・不動産','phone' => '011-678-9012', 'address' => '北海道札幌市中央区北1条西2-9',       'employee_count' => 430, 'website' => 'https://sunrise-const.co.jp',       'tenant_id' => $tenantIds[1]],
            ['company_name' => '株式会社メディカルケア',      'industry' => '医療・福祉',  'phone' => '092-789-0123', 'address' => '福岡県福岡市博多区博多駅前1-1-1',    'employee_count' => 180, 'website' => 'https://medical-care.co.jp',        'tenant_id' => $tenantIds[2]],
            ['company_name' => 'アジアトレード株式会社',      'industry' => '商社・貿易',  'phone' => '03-8901-2345', 'address' => '東京都港区赤坂1-2-3',                'employee_count' => 95,  'website' => 'https://asia-trade.co.jp',          'tenant_id' => $tenantIds[2]],
            ['company_name' => '株式会社スマートホーム',      'industry' => '不動産・住宅','phone' => '06-9012-3456', 'address' => '大阪府大阪市中央区難波4-1-1',        'employee_count' => 67,  'website' => 'https://smart-home.co.jp',          'tenant_id' => $tenantIds[2]],
        ];
        $customerIds = [];
        foreach ($customers as $customer) {
            $id = DB::table('customers')->insertGetId(array_merge($customer, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $customerIds[] = ['id' => $id, 'tenant_id' => $customer['tenant_id']];
        }

        // ===== Contacts (20人) =====
        $contacts = [
            ['name' => '山田 太郎',   'email' => 'yamada.t@yamada-mfg.co.jp',       'phone' => '03-1234-5679', 'position' => '営業部長',       'department' => '営業部',     'customer_index' => 0],
            ['name' => '山田 花子',   'email' => 'yamada.h@yamada-mfg.co.jp',       'phone' => '03-1234-5680', 'position' => '購買担当',       'department' => '購買部',     'customer_index' => 0],
            ['name' => '木村 亮介',   'email' => 'kimura.r@tokyo-its.co.jp',        'phone' => '03-2345-6790', 'position' => 'CTO',            'department' => '技術部',     'customer_index' => 1],
            ['name' => '松本 由美',   'email' => 'matsumoto.y@tokyo-its.co.jp',     'phone' => '03-2345-6791', 'position' => '営業マネージャー','department' => '営業部',     'customer_index' => 1],
            ['name' => '井上 浩二',   'email' => 'inoue.k@green-energy.co.jp',      'phone' => '06-3456-7891', 'position' => '代表取締役',     'department' => '経営企画室', 'customer_index' => 2],
            ['name' => '橋本 恵子',   'email' => 'hashimoto.k@green-energy.co.jp',  'phone' => '06-3456-7892', 'position' => '事業開発担当',   'department' => '事業開発部', 'customer_index' => 2],
            ['name' => '遠藤 修',     'email' => 'endo.o@tech-innovation.co.jp',    'phone' => '03-0123-4568', 'position' => 'CEO',            'department' => '経営企画室', 'customer_index' => 3],
            ['name' => '池田 沙織',   'email' => 'ikeda.s@tech-innovation.co.jp',   'phone' => '03-0123-4569', 'position' => '営業担当',       'department' => '営業部',     'customer_index' => 3],
            ['name' => '斎藤 誠一',   'email' => 'saito.s@nihon-logistics.co.jp',   'phone' => '045-456-7891', 'position' => '物流部長',       'department' => '物流部',     'customer_index' => 4],
            ['name' => '清水 美恵',   'email' => 'shimizu.m@nihon-logistics.co.jp', 'phone' => '045-456-7892', 'position' => '調達担当',       'department' => '調達部',     'customer_index' => 4],
            ['name' => '藤田 健太',   'email' => 'fujita.k@food-planet.co.jp',      'phone' => '052-567-8902', 'position' => '商品開発部長',   'department' => '商品開発部', 'customer_index' => 5],
            ['name' => '西村 玲子',   'email' => 'nishimura.r@food-planet.co.jp',   'phone' => '052-567-8903', 'position' => '営業担当',       'department' => '営業部',     'customer_index' => 5],
            ['name' => '岡田 博',     'email' => 'okada.h@sunrise-const.co.jp',     'phone' => '011-678-9013', 'position' => '工事部長',       'department' => '工事部',     'customer_index' => 6],
            ['name' => '長谷川 彩',   'email' => 'hasegawa.a@sunrise-const.co.jp',  'phone' => '011-678-9014', 'position' => '営業課長',       'department' => '営業部',     'customer_index' => 6],
            ['name' => '三浦 康介',   'email' => 'miura.k@medical-care.co.jp',      'phone' => '092-789-0124', 'position' => '院長',           'department' => '医局',       'customer_index' => 7],
            ['name' => '中島 佳代',   'email' => 'nakajima.k@medical-care.co.jp',   'phone' => '092-789-0125', 'position' => '事務長',         'department' => '総務部',     'customer_index' => 7],
            ['name' => '石田 俊夫',   'email' => 'ishida.t@asia-trade.co.jp',       'phone' => '03-8901-2346', 'position' => '海外営業部長',   'department' => '海外営業部', 'customer_index' => 8],
            ['name' => '村田 奈緒',   'email' => 'murata.n@asia-trade.co.jp',       'phone' => '03-8901-2347', 'position' => '貿易担当',       'department' => '貿易部',     'customer_index' => 8],
            ['name' => '小野 浩',     'email' => 'ono.h@smart-home.co.jp',          'phone' => '06-9012-3457', 'position' => '代表取締役',     'department' => '経営企画室', 'customer_index' => 9],
            ['name' => '後藤 真理',   'email' => 'goto.m@smart-home.co.jp',         'phone' => '06-9012-3458', 'position' => '販売促進担当',   'department' => '販売促進部', 'customer_index' => 9],
        ];
        foreach ($contacts as $contact) {
            $customer = $customerIds[$contact['customer_index']];
            DB::table('contacts')->insert([
                'name'        => $contact['name'],
                'email'       => $contact['email'],
                'phone'       => $contact['phone'],
                'position'    => $contact['position'],
                'department'  => $contact['department'],
                'customer_id' => $customer['id'],
                'tenant_id'   => $customer['tenant_id'],
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        // ===== Deals (10件) =====
        $deals = [
            ['title' => '生産管理システム導入支援',     'amount' => 5800000,  'status' => '提案', 'probability' => 60,  'expected_close_date' => '2026-05-31', 'customer_index' => 0],
            ['title' => 'クラウド移行プロジェクト',     'amount' => 12000000, 'status' => '交渉', 'probability' => 75,  'expected_close_date' => '2026-04-30', 'customer_index' => 1],
            ['title' => '再生可能エネルギー設備導入',   'amount' => 28000000, 'status' => '成約', 'probability' => 100, 'expected_close_date' => '2026-03-31', 'actual_close_date' => '2026-03-18', 'customer_index' => 2],
            ['title' => 'AI営業支援ツール開発',         'amount' => 22000000, 'status' => '交渉', 'probability' => 70,  'expected_close_date' => '2026-05-01', 'customer_index' => 3],
            ['title' => '物流管理システムリプレース',   'amount' => 9500000,  'status' => '新規', 'probability' => 40,  'expected_close_date' => '2026-06-30', 'customer_index' => 4],
            ['title' => '食品トレーサビリティ導入',     'amount' => 4200000,  'status' => '提案', 'probability' => 55,  'expected_close_date' => '2026-05-15', 'customer_index' => 5],
            ['title' => '建設現場DX化支援',             'amount' => 7600000,  'status' => '交渉', 'probability' => 80,  'expected_close_date' => '2026-04-15', 'customer_index' => 6],
            ['title' => '電子カルテシステム更新',       'amount' => 15000000, 'status' => '成約', 'probability' => 100, 'expected_close_date' => '2026-03-20', 'actual_close_date' => '2026-03-18', 'customer_index' => 7],
            ['title' => '貿易管理プラットフォーム構築', 'amount' => 6300000,  'status' => '新規', 'probability' => 25,  'expected_close_date' => '2026-07-31', 'customer_index' => 8],
            ['title' => 'スマートホームIoT導入',        'amount' => 3800000,  'status' => '提案', 'probability' => 50,  'expected_close_date' => '2026-06-15', 'customer_index' => 9],
        ];
        foreach ($deals as $deal) {
            $customer = $customerIds[$deal['customer_index']];
            $userId = $adminByTenant[$customer['tenant_id']];
            DB::table('deals')->insert([
                'title'               => $deal['title'],
                'amount'              => $deal['amount'],
                'status'              => $deal['status'],
                'probability'         => $deal['probability'],
                'expected_close_date' => $deal['expected_close_date'],
                'actual_close_date'   => $deal['actual_close_date'] ?? null,
                'customer_id'         => $customer['id'],
                'user_id'             => $userId,
                'tenant_id'           => $customer['tenant_id'],
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }

        // ===== Tasks (10件) =====
        $tasks = [
            ['title' => '提案書作成',             'description' => '生産管理システムの提案書を作成する',       'due_date' => '2026-03-25', 'priority' => '高',   'status' => '進行中', 'customer_index' => 0],
            ['title' => 'デモ環境準備',           'description' => 'クラウド移行のデモ環境をセットアップする', 'due_date' => '2026-03-28', 'priority' => '高',   'status' => '未着手', 'customer_index' => 1],
            ['title' => '契約書レビュー',         'description' => '再生可能エネルギー設備の契約書を確認する', 'due_date' => '2026-03-20', 'priority' => '中',   'status' => '完了',   'customer_index' => 2],
            ['title' => '開発スコープ確認',       'description' => 'AI営業支援ツールの開発スコープを確認する', 'due_date' => '2026-03-30', 'priority' => '高',   'status' => '進行中', 'customer_index' => 3],
            ['title' => '要件定義ヒアリング',     'description' => '物流管理システムの要件をヒアリングする',   'due_date' => '2026-04-05', 'priority' => '中',   'status' => '未着手', 'customer_index' => 4],
            ['title' => '競合調査',               'description' => '食品トレーサビリティ市場の競合を調査する', 'due_date' => '2026-04-01', 'priority' => '低',   'status' => '進行中', 'customer_index' => 5],
            ['title' => '現地視察アポイント取得', 'description' => '建設現場のDX化に向けて現地視察を調整する', 'due_date' => '2026-03-27', 'priority' => '高',   'status' => '未着手', 'customer_index' => 6],
            ['title' => '納品確認',               'description' => '電子カルテシステムの納品内容を確認する',   'due_date' => '2026-03-22', 'priority' => '高',   'status' => '完了',   'customer_index' => 7],
            ['title' => 'ニーズヒアリング',       'description' => '貿易管理の課題をヒアリングする',           'due_date' => '2026-04-10', 'priority' => '低',   'status' => '未着手', 'customer_index' => 8],
            ['title' => 'PoC提案',                'description' => 'スマートホームIoTのPoC提案書を作成する',   'due_date' => '2026-04-03', 'priority' => '中',   'status' => '進行中', 'customer_index' => 9],
        ];
        foreach ($tasks as $task) {
            $customer = $customerIds[$task['customer_index']];
            DB::table('tasks')->insert([
                'title'       => $task['title'],
                'description' => $task['description'],
                'due_date'    => $task['due_date'],
                'priority'    => $task['priority'],
                'status'      => $task['status'],
                'user_id'     => $adminByTenant[$customer['tenant_id']],
                'customer_id' => $customer['id'],
                'tenant_id'   => $customer['tenant_id'],
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        // ===== Activities (10件) =====
        $activities = [
            ['type' => '訪問',   'subject' => '初回商談',           'description' => '生産管理システムについて初回商談を実施。担当者の課題をヒアリング。',         'activity_date' => '2026-03-10', 'customer_index' => 0],
            ['type' => '電話',   'subject' => 'フォローアップ電話', 'description' => 'クラウド移行の進捗確認のため電話。来週デモ実施の日程調整完了。',             'activity_date' => '2026-03-12', 'customer_index' => 1],
            ['type' => 'メール', 'subject' => '契約締結のご連絡',   'description' => '再生可能エネルギー設備導入の契約締結をメールにて正式通知。',                 'activity_date' => '2026-03-15', 'customer_index' => 2],
            ['type' => '電話',   'subject' => '要件確認電話',       'description' => 'AI営業支援ツールの要件について電話確認。追加機能要望を把握。',               'activity_date' => '2026-03-17', 'customer_index' => 3],
            ['type' => '訪問',   'subject' => '現地調査訪問',       'description' => '物流センターを訪問し、現行システムの課題を調査。写真撮影および記録。',       'activity_date' => '2026-03-08', 'customer_index' => 4],
            ['type' => '訪問',   'subject' => '提案プレゼン',       'description' => '食品トレーサビリティシステムの提案プレゼンを実施。担当者から好感触。',       'activity_date' => '2026-03-14', 'customer_index' => 5],
            ['type' => '電話',   'subject' => '価格交渉電話',       'description' => '建設現場DX化の価格について電話にて交渉。値引き要望あり。社内確認中。',     'activity_date' => '2026-03-16', 'customer_index' => 6],
            ['type' => '訪問',   'subject' => '納品立会い',         'description' => '電子カルテシステムの納品に立会い。動作確認完了。担当者満足。',               'activity_date' => '2026-03-18', 'customer_index' => 7],
            ['type' => 'メール', 'subject' => '資料送付',           'description' => '貿易管理プラットフォームの会社概要・製品資料をメール送付。',                 'activity_date' => '2026-03-11', 'customer_index' => 8],
            ['type' => '訪問',   'subject' => 'PoC説明会',          'description' => 'スマートホームIoTのPoC内容を説明。技術的な質問多数あり。次回詳細説明予定。', 'activity_date' => '2026-03-13', 'customer_index' => 9],
        ];
        foreach ($activities as $activity) {
            $customer = $customerIds[$activity['customer_index']];
            DB::table('activities')->insert([
                'type'          => $activity['type'],
                'subject'       => $activity['subject'],
                'content'       => $activity['description'],
                'activity_date' => $activity['activity_date'],
                'user_id'       => $adminByTenant[$customer['tenant_id']],
                'customer_id'   => $customer['id'],
                'tenant_id'     => $customer['tenant_id'],
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        $this->command->info('テストデータの作成が完了しました！');
        $this->command->info('テナント: 3社 / ユーザー: 10人 / 顧客: 10社 / 連絡先: 20人 / 商談: 10件 / タスク: 10件 / 活動: 10件');
    }

    // Supabase Admin APIでユーザー作成
    private function createSupabaseUser(string $email, string $password): ?string
    {
        $response = Http::withHeaders([
            'apikey'        => $this->serviceRoleKey,
            'Authorization' => "Bearer {$this->serviceRoleKey}",
            'Content-Type'  => 'application/json',
        ])->post("{$this->supabaseUrl}/auth/v1/admin/users", [
            'email'            => $email,
            'password'         => $password,
            'email_confirm'    => true, // メール確認不要
        ]);

        if ($response->successful()) {
            $uid = $response->json('id');
            $this->command->info("Supabase Auth ユーザー作成: {$email} ({$uid})");
            return $uid;
        }

        // 既に存在する場合はUIDを取得
        if ($response->status() === 422) {
            $this->command->warn("既存ユーザーのためスキップ: {$email}");
            return $this->getSupabaseUserId($email);
        }

        $this->command->error("Supabase Authユーザー作成失敗: {$email} - " . $response->body());
        return null;
    }

    // 既存ユーザーのUIDを取得
    private function getSupabaseUserId(string $email): ?string
    {
        $response = Http::withHeaders([
            'apikey'        => $this->serviceRoleKey,
            'Authorization' => "Bearer {$this->serviceRoleKey}",
        ])->get("{$this->supabaseUrl}/auth/v1/admin/users", [
            'email' => $email,
        ]);

        if ($response->successful()) {
            $users = $response->json('users', []);
            foreach ($users as $user) {
                if ($user['email'] === $email) {
                    return $user['id'];
                }
            }
        }
        return null;
    }
}
