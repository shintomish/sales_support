<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;

class SeedTestUsers extends Command
{
    protected $signature = 'seed:test-users {--force : 確認なしで実行}';
    protected $description = 'テストユーザーをSupabase Auth + usersテーブルに一括登録（既存は上書き）';

    // CSVから抽出したユーザーデータ
    private array $users = [
        ['name' => '新冨 泰明',   'email' => 'shintomi.sh@gmail.com',          'tenant_id' => 1, 'role' => 'super_admin'],
        ['name' => '関 秀樹',     'email' => 'h-seki@aizen-sol.co.jp',          'tenant_id' => 1, 'role' => 'tenant_admin'],
        ['name' => '石﨑 啓',     'email' => 'h-ishizaki@aizen-sol.co.jp',      'tenant_id' => 1, 'role' => 'tenant_user'],
        ['name' => '榎本 凌',     'email' => 'r-enomoto@aizen-sol.co.jp',       'tenant_id' => 1, 'role' => 'tenant_user'],
        ['name' => '藤崎 翔平',   'email' => 's-fujisaki@aizen-sol.co.jp',      'tenant_id' => 1, 'role' => 'tenant_user'],
        ['name' => '松村 悠大',   'email' => 'y-matsumura@aizen-sol.co.jp',     'tenant_id' => 1, 'role' => 'tenant_user'],
        ['name' => '末岡 真衣',   'email' => 'm-sueoka@aizen-sol.co.jp',        'tenant_id' => 1, 'role' => 'tenant_user'],
        ['name' => '伊東 一樹',   'email' => 'k-ito@aizen-sol.co.jp',           'tenant_id' => 1, 'role' => 'tenant_user'],
        ['name' => '土屋 颯斗',   'email' => 'h-tsuchiya@aizen-sol.co.jp',      'tenant_id' => 1, 'role' => 'tenant_user'],
        ['name' => '鈴木 健一',   'email' => 'suzuki.k@izen-solution.jp',       'tenant_id' => 2, 'role' => 'tenant_admin'],
        ['name' => '佐藤 美咲',   'email' => 'sato.m@izen-solution.jp',         'tenant_id' => 2, 'role' => 'tenant_user'],
        ['name' => '高橋 雄太',   'email' => 'takahashi.y@izen-solution.jp',    'tenant_id' => 2, 'role' => 'tenant_user'],
        ['name' => '伊藤 直樹',   'email' => 'ito.n@towa-shoji.co.jp',          'tenant_id' => 2, 'role' => 'tenant_user'],
        ['name' => '渡辺 さくら', 'email' => 'watanabe.s@towa-shoji.co.jp',     'tenant_id' => 2, 'role' => 'tenant_user'],
        ['name' => '中村 隆',     'email' => 'nakamura.t@towa-shoji.co.jp',     'tenant_id' => 2, 'role' => 'tenant_user'],
        ['name' => '小林 朋子',   'email' => 'kobayashi.t@next-stage.jp',       'tenant_id' => 3, 'role' => 'tenant_admin'],
        ['name' => '加藤 誠',     'email' => 'kato.m@next-stage.jp',            'tenant_id' => 3, 'role' => 'tenant_user'],
        ['name' => '吉田 奈々',   'email' => 'yoshida.n@next-stage.jp',         'tenant_id' => 3, 'role' => 'tenant_user'],
    ];

    public function handle(): int
    {
        $supabaseUrl = config('services.supabase.url');
        $serviceKey  = config('services.supabase.service_role_key');

        if (!$supabaseUrl || !$serviceKey) {
            $this->error('SUPABASE_URL または SUPABASE_SERVICE_ROLE_KEY が設定されていません。');
            return 1;
        }

        if (!$this->option('force')) {
            if (!$this->confirm('usersテーブルの全データをCSVで上書きします。よろしいですか？')) {
                $this->info('キャンセルしました。');
                return 0;
            }
        }

        // ── Step 1: Supabase Auth の既存ユーザーを全取得 ──
        $this->info('Supabase Auth の既存ユーザーを取得中...');
        $existingAuthUsers = $this->fetchAllAuthUsers($supabaseUrl, $serviceKey);
        $this->info('  取得件数: ' . count($existingAuthUsers));

        // ── Step 2: CSVにないAuthユーザーを削除 ──
        $csvEmails = array_column($this->users, 'email');
        $toDelete  = array_filter($existingAuthUsers, fn($u) => !in_array($u['email'], $csvEmails, true));

        foreach ($toDelete as $u) {
            $this->deleteAuthUser($supabaseUrl, $serviceKey, $u['id']);
            $this->line("  Auth削除: {$u['email']}");
        }

        // ── Step 3: 各ユーザーをUpsert（Auth + usersテーブル） ──
        $this->info('ユーザーを登録/更新中...');

        // メール→Auth UIDのマップを作成
        $emailToUid = [];
        foreach ($existingAuthUsers as $u) {
            $emailToUid[$u['email']] = $u['id'];
        }

        foreach ($this->users as $userData) {
            $email = $userData['email'];

            if (isset($emailToUid[$email])) {
                // 既存 → パスワードだけ更新
                $uid = $emailToUid[$email];
                $this->updateAuthUser($supabaseUrl, $serviceKey, $uid);
                $this->line("  Auth更新: {$email}");
            } else {
                // 新規作成
                $uid = $this->createAuthUser($supabaseUrl, $serviceKey, $email);
                if (!$uid) {
                    $this->error("  Auth作成失敗: {$email}");
                    continue;
                }
                $this->line("  Auth作成: {$email} (uid={$uid})");
            }

            // usersテーブルにupsert（emailをキー）
            DB::table('users')->upsert(
                [
                    'supabase_uid'      => $uid,
                    'tenant_id'         => $userData['tenant_id'],
                    'name'              => $userData['name'],
                    'email'             => $email,
                    'password'          => Hash::make('password'),
                    'role'              => $userData['role'],
                    'email_verified_at' => now(),
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ],
                ['email'],  // conflict key
                ['supabase_uid', 'tenant_id', 'name', 'password', 'role', 'email_verified_at', 'updated_at']
            );
        }

        // ── Step 4: CSVにないusersレコードを削除 ──
        $deleted = DB::table('users')->whereNotIn('email', $csvEmails)->delete();
        if ($deleted > 0) {
            $this->line("  usersテーブル不要レコード削除: {$deleted}件");
        }

        $this->info('完了しました。登録ユーザー数: ' . count($this->users));
        return 0;
    }

    private function authHttp(string $key): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$key}",
            'apikey'        => $key,
        ]);
    }

    private function fetchAllAuthUsers(string $url, string $key): array
    {
        $users   = [];
        $page    = 1;
        $perPage = 1000;

        do {
            $res = $this->authHttp($key)
                ->get("{$url}/auth/v1/admin/users", ['page' => $page, 'per_page' => $perPage]);

            if (!$res->successful()) {
                $this->error('Auth一覧取得失敗: ' . $res->body());
                break;
            }

            $body  = $res->json();
            $batch = $body['users'] ?? [];
            $users = array_merge($users, $batch);
            $page++;
        } while (count($batch) === $perPage);

        return $users;
    }

    private function createAuthUser(string $url, string $key, string $email): ?string
    {
        $res = $this->authHttp($key)
            ->post("{$url}/auth/v1/admin/users", [
                'email'         => $email,
                'password'      => 'password',
                'email_confirm' => true,
            ]);

        if (!$res->successful()) {
            $this->error("  Auth作成APIエラー ({$email}): " . $res->body());
            return null;
        }

        return $res->json('id');
    }

    private function updateAuthUser(string $url, string $key, string $uid): void
    {
        $res = $this->authHttp($key)
            ->put("{$url}/auth/v1/admin/users/{$uid}", [
                'password'      => 'password',
                'email_confirm' => true,
            ]);

        if (!$res->successful()) {
            $this->error("  Auth更新APIエラー (uid={$uid}): " . $res->body());
        }
    }

    private function deleteAuthUser(string $url, string $key, string $uid): void
    {
        $this->authHttp($key)->delete("{$url}/auth/v1/admin/users/{$uid}");
    }
}
