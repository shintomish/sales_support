<?php

namespace App\Console\Commands;

use App\Services\GoogleCredentialService;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;
use Google\Cloud\SecretManager\V1\AddSecretVersionRequest;
use Google\Cloud\SecretManager\V1\SecretPayload;

/**
 * Vision API サービスアカウントキーのローテーションコマンド。
 *
 * 処理フロー:
 *   1. 現在の認証情報（キーID、メール等）を取得
 *   2. GCP IAM API で新しいキーを生成
 *   3. Secret Manager の最新バージョンを更新
 *   4. ローカルの bootstrap ファイルを新しいキーで上書き
 *   5. 認証情報キャッシュをクリア
 *   6. 古い IAM キーを削除
 *
 * 前提権限（サービスアカウントに付与）:
 *   - roles/iam.serviceAccountKeyAdmin（自身のキーに対して）
 *   - roles/secretmanager.secretVersionManager
 */
class RotateVisionApiKey extends Command
{
    protected $signature = 'vision:rotate-key {--dry-run : 実際の変更を行わずに確認のみ}';
    protected $description = 'Vision API サービスアカウントキーをローテーションする';

    // IAM API スコープ
    private const IAM_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    public function __construct(private readonly GoogleCredentialService $credentialService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $credentialsFile = config('services.google_vision.credentials');
        $projectId       = config('services.google_vision.project_id');
        $secretName      = config('services.google_vision.secret_name');

        if (!$projectId || !$secretName) {
            $this->error('GOOGLE_CLOUD_PROJECT_ID または GOOGLE_SECRET_NAME が未設定のためローテーション不可。');
            return 1;
        }

        // 現在の認証情報を読み込む
        try {
            $currentCreds = $this->credentialService->getCredentials();
        } catch (\Throwable $e) {
            $this->error('現在の認証情報を取得できません: ' . $e->getMessage());
            return 1;
        }

        $serviceAccountEmail = $currentCreds['client_email'];
        $oldKeyId            = $currentCreds['private_key_id'];

        $this->info("サービスアカウント: {$serviceAccountEmail}");
        $this->info("ローテーション対象キーID: {$oldKeyId}");

        if ($dryRun) {
            $this->warn('[DRY-RUN] 実際の変更は行いません。');
            return 0;
        }

        // ── Step 1: IAM アクセストークン取得 ──
        $this->line('アクセストークンを取得中...');
        $accessToken = $this->getAccessToken($currentCreds);

        // ── Step 2: 新しいキーを IAM API で生成 ──
        $this->line('新しいキーを生成中...');
        $newKeyJson = $this->createServiceAccountKey($accessToken, $projectId, $serviceAccountEmail);
        $newKeyData = json_decode($newKeyJson, true);
        $newKeyId   = $newKeyData['private_key_id'];
        $this->info("新しいキーID: {$newKeyId}");

        // ── Step 3: Secret Manager を更新 ──
        $this->line('Secret Manager を更新中...');
        $this->updateSecret($currentCreds, $credentialsFile, $projectId, $secretName, $newKeyJson);

        // ── Step 4: ローカルファイルを上書き ──
        $this->line('ローカル bootstrap ファイルを更新中...');
        file_put_contents($credentialsFile, $newKeyJson);
        $this->info("更新完了: {$credentialsFile}");

        // ── Step 5: 認証情報キャッシュをクリア ──
        $this->credentialService->clearCache();

        // ── Step 6: 古いキーを削除 ──
        $this->line("古いキー（{$oldKeyId}）を削除中...");
        $this->deleteServiceAccountKey($accessToken, $projectId, $serviceAccountEmail, $oldKeyId);
        $this->info("古いキー削除完了");

        Log::info("[RotateVisionApiKey] キーローテーション完了 old={$oldKeyId} new={$newKeyId}");

        $this->newLine();
        $this->info('ローテーション完了。次回は90日後に実行してください。');

        return 0;
    }

    // ──────────────────────────────────────────────────

    private function getAccessToken(array $creds): string
    {
        $credentials = new ServiceAccountCredentials(
            self::IAM_SCOPE,
            $creds
        );
        $token = $credentials->fetchAuthToken();
        return $token['access_token'];
    }

    private function createServiceAccountKey(string $token, string $project, string $email): string
    {
        $url = "https://iam.googleapis.com/v1/projects/{$project}/serviceAccounts/{$email}/keys";

        $response = Http::withToken($token)
            ->post($url, ['keyAlgorithm' => 'KEY_ALG_RSA_2048']);

        if (!$response->successful()) {
            throw new \RuntimeException('新しいキーの作成に失敗: ' . $response->body());
        }

        // GCP は base64 エンコードされた JSON を返す
        $encoded = $response->json('privateKeyData');
        return base64_decode($encoded);
    }

    private function updateSecret(
        array  $currentCreds,
        string $credentialsFile,
        string $project,
        string $secretName,
        string $newKeyJson
    ): void {
        $clientOptions = [];
        if (file_exists($credentialsFile)) {
            $clientOptions['credentials'] = $credentialsFile;
        }

        $client     = new SecretManagerServiceClient($clientOptions);
        $secretPath = "projects/{$project}/secrets/{$secretName}";
        $payload    = (new SecretPayload())->setData($newKeyJson);
        $addReq     = (new AddSecretVersionRequest())
            ->setParent($secretPath)
            ->setPayload($payload);
        $version    = $client->addSecretVersion($addReq);
        $client->close();

        $this->info('Secret Manager バージョン追加: ' . $version->getName());
    }

    private function deleteServiceAccountKey(string $token, string $project, string $email, string $keyId): void
    {
        $url = "https://iam.googleapis.com/v1/projects/{$project}/serviceAccounts/{$email}/keys/{$keyId}";

        $response = Http::withToken($token)->delete($url);

        if (!$response->successful()) {
            // 削除失敗はログに残すが処理は継続（新しいキーは既に有効）
            Log::warning("[RotateVisionApiKey] 古いキー削除失敗 keyId={$keyId}: " . $response->body());
            $this->warn("古いキーの削除に失敗しました（手動で削除してください）: {$keyId}");
        }
    }
}
