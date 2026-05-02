<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Supabase Auth Admin API のラッパー
 *
 * service_role key を使用して auth.users を直接操作する。
 * テナント別ユーザー管理 (#14) で利用。
 */
class SupabaseAuthAdminService
{
    private string $url;
    private string $serviceRoleKey;

    public function __construct()
    {
        $this->url            = rtrim(config('services.supabase.url'), '/');
        $this->serviceRoleKey = config('services.supabase.service_role_key');
    }

    /**
     * ユーザーを新規作成して招待メール（パスワード設定リンク）を送信する。
     *
     * @return string 作成された Supabase auth.users.id (uuid)
     */
    public function inviteUser(string $email, ?string $redirectTo = null): string
    {
        $payload = ['email' => $email];

        $response = $this->client()
            ->post(
                $this->url . '/auth/v1/invite' . ($redirectTo ? '?redirect_to=' . urlencode($redirectTo) : ''),
                $payload,
            );

        if (!$response->successful()) {
            throw new \RuntimeException('Supabase invite failed: ' . $response->body());
        }

        $data = $response->json();
        if (empty($data['id'])) {
            throw new \RuntimeException('Supabase invite returned no id: ' . $response->body());
        }

        return $data['id'];
    }

    /**
     * ユーザー属性を更新する（email等）
     *
     * @param array<string,mixed> $attributes 例: ['email' => 'new@example.com']
     */
    public function updateUser(string $uid, array $attributes): void
    {
        $response = $this->client()
            ->put($this->url . "/auth/v1/admin/users/{$uid}", $attributes);

        if (!$response->successful()) {
            throw new \RuntimeException('Supabase user update failed: ' . $response->body());
        }
    }

    /**
     * ユーザーを完全削除する。
     */
    public function deleteUser(string $uid): void
    {
        $response = $this->client()
            ->delete($this->url . "/auth/v1/admin/users/{$uid}");

        if (!$response->successful() && $response->status() !== 404) {
            throw new \RuntimeException('Supabase user delete failed: ' . $response->body());
        }
    }

    /**
     * パスワードリセット（recovery）メールを送信する。
     * 招待メール再送として利用可能。
     */
    public function sendRecovery(string $email, ?string $redirectTo = null): void
    {
        $url = $this->url . '/auth/v1/recover'
            . ($redirectTo ? '?redirect_to=' . urlencode($redirectTo) : '');

        $response = $this->client()->post($url, ['email' => $email]);

        if (!$response->successful()) {
            throw new \RuntimeException('Supabase recovery send failed: ' . $response->body());
        }
    }

    private function client()
    {
        return Http::withHeaders([
            'apikey'        => $this->serviceRoleKey,
            'Authorization' => 'Bearer ' . $this->serviceRoleKey,
            'Content-Type'  => 'application/json',
        ])->acceptJson();
    }
}
