<?php

namespace App\Services;

use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;
use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Vision API 認証情報を Secret Manager から取得・キャッシュするサービス。
 * Secret Manager が利用不可の場合はローカルファイルにフォールバック。
 */
class GoogleCredentialService
{
    private ?string $projectId;
    private ?string $secretName;
    private string  $credentialsFile;

    // キャッシュキー / TTL
    private const CACHE_KEY = 'google_vision_credentials';
    private const CACHE_TTL = 3600; // 1時間

    public function __construct()
    {
        $this->projectId      = config('services.google_vision.project_id');
        $this->secretName     = config('services.google_vision.secret_name');
        $this->credentialsFile = config('services.google_vision.credentials');
    }

    /**
     * Vision API 認証情報の配列を返す。
     * Secret Manager → ローカルファイル の順で試みる。
     */
    public function getCredentials(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            // Secret Manager が設定されている場合のみ試みる
            if ($this->projectId && $this->secretName) {
                try {
                    $creds = $this->loadFromSecretManager();
                    Log::debug('[GoogleCredential] Secret Manager から認証情報を取得');
                    return $creds;
                } catch (\Throwable $e) {
                    Log::warning('[GoogleCredential] Secret Manager 取得失敗、ファイルにフォールバック: ' . $e->getMessage());
                }
            }

            return $this->loadFromFile();
        });
    }

    /**
     * キャッシュをクリアして次回アクセス時に再取得させる。
     * キーローテーション後に呼び出す。
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // ──────────────────────────────────────────────────

    private function loadFromSecretManager(): array
    {
        // ローカルファイルが存在すれば bootstrap 認証として使用
        $clientOptions = [];
        if (file_exists($this->credentialsFile)) {
            $clientOptions['credentials'] = $this->credentialsFile;
        }

        $client  = new SecretManagerServiceClient($clientOptions);
        $version = "projects/{$this->projectId}/secrets/{$this->secretName}/versions/latest";

        $request  = (new AccessSecretVersionRequest())->setName($version);
        $response = $client->accessSecretVersion($request);

        $json = $response->getPayload()->getData();
        $client->close();

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function loadFromFile(): array
    {
        if (!file_exists($this->credentialsFile)) {
            throw new \RuntimeException("認証情報ファイルが見つかりません: {$this->credentialsFile}");
        }

        $json = file_get_contents($this->credentialsFile);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
