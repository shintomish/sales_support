<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;
use Google\Cloud\SecretManager\V1\CreateSecretRequest;
use Google\Cloud\SecretManager\V1\AddSecretVersionRequest;
use Google\Cloud\SecretManager\V1\Secret;
use Google\Cloud\SecretManager\V1\Replication;
use Google\Cloud\SecretManager\V1\Replication\Automatic;
use Google\Cloud\SecretManager\V1\SecretPayload;

/**
 * 既存の google-vision.json を Secret Manager に初期登録するコマンド。
 * 初回セットアップ時に1度だけ実行する。
 *
 * 事前に GCP コンソールで以下の権限を SA に付与してください:
 *   roles/secretmanager.admin（初期登録のみ）
 *   roles/secretmanager.secretAccessor（アプリ実行時）
 *   roles/iam.serviceAccountKeyAdmin（キーローテーション）
 */
class InitVisionSecret extends Command
{
    protected $signature = 'vision:init-secret';
    protected $description = 'google-vision.json の内容を Secret Manager に初期登録する';

    public function handle(): int
    {
        $projectId       = config('services.google_vision.project_id');
        $secretName      = config('services.google_vision.secret_name');
        $credentialsFile = config('services.google_vision.credentials');

        if (!$projectId || !$secretName) {
            $this->error('GOOGLE_CLOUD_PROJECT_ID または GOOGLE_SECRET_NAME が未設定です。');
            return 1;
        }

        if (!file_exists($credentialsFile)) {
            $this->error("認証情報ファイルが見つかりません: {$credentialsFile}");
            return 1;
        }

        $this->info("プロジェクト: {$projectId}");
        $this->info("シークレット名: {$secretName}");

        $jsonContent = file_get_contents($credentialsFile);
        $keyData     = json_decode($jsonContent, true);
        $keyId       = $keyData['private_key_id'] ?? 'unknown';
        $this->info("現在のキーID: {$keyId}");

        $client   = new SecretManagerServiceClient(['credentials' => $credentialsFile]);
        $parent   = "projects/{$projectId}";
        $fullName = "{$parent}/secrets/{$secretName}";

        // シークレット自体が存在するか確認 → なければ作成
        $versionName = null;

        try {
            $client->getSecret($fullName);
            $this->line('既存シークレットにバージョンを追加します...');
        } catch (\Google\ApiCore\ApiException $e) {
            if ($e->getStatus() === 'NOT_FOUND') {
                $this->line('シークレットを新規作成します...');
                $secret = (new Secret())
                    ->setReplication(
                        (new Replication())->setAutomatic(new Automatic())
                    );
                $createReq = (new CreateSecretRequest())
                    ->setParent($parent)
                    ->setSecretId($secretName)
                    ->setSecret($secret);
                $client->createSecret($createReq);
                $this->info("シークレット作成完了: {$fullName}");
            } else {
                throw $e;
            }
        }

        // バージョン追加
        $payload    = (new SecretPayload())->setData($jsonContent);
        $addReq     = (new AddSecretVersionRequest())
            ->setParent($fullName)
            ->setPayload($payload);
        $version    = $client->addSecretVersion($addReq);
        $versionName = $version->getName();

        $client->close();

        $this->info("Secret Manager への登録完了: {$versionName}");
        $this->newLine();
        $this->line('次のステップ:');
        $this->line('  1. .env に以下を追加して再起動してください:');
        $this->line("     GOOGLE_CLOUD_PROJECT_ID={$projectId}");
        $this->line("     GOOGLE_SECRET_NAME={$secretName}");
        $this->line('  2. アプリが Secret Manager から読み込めることを確認後、');
        $this->line('     サーバーの google-vision.json は削除またはバックアップ可能です');

        return 0;
    }
}
