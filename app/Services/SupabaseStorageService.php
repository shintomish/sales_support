<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class SupabaseStorageService
{
    private string $url;
    private string $serviceRoleKey;
    private string $bucket;

    public function __construct()
    {
        $this->url            = rtrim(config('services.supabase.url'), '/');
        $this->serviceRoleKey = config('services.supabase.service_role_key');
        $this->bucket         = config('services.supabase.bucket');
    }

    /**
     * UploadedFile を Supabase Storage にアップロードして公開URLを返す
     */
    public function upload(UploadedFile $file, string $folder = 'default'): string
    {
        $ext      = $file->getClientOriginalExtension();
        $filename = $folder . '/' . time() . '_' . Str::random(12) . '.' . $ext;
        $endpoint = "{$this->url}/storage/v1/object/{$this->bucket}/{$filename}";

        $response = \Http::withHeaders([
            'Authorization' => "Bearer {$this->serviceRoleKey}",
            'Content-Type'  => $file->getMimeType(),
            'x-upsert'      => 'false',
        ])->withBody(
            file_get_contents($file->getRealPath()),
            $file->getMimeType()
        )->post($endpoint);

        if ($response->failed()) {
            throw new \Exception('Supabase Storage upload failed: ' . $response->body());
        }

        // 公開URLを返す
        return "{$this->url}/storage/v1/object/public/{$this->bucket}/{$filename}";
    }

    /**
     * Supabase Storage からファイルを削除する
     */
    public function delete(string $publicUrl): void
    {
        // URLからファイルパスを抽出
        $pattern = "/storage\/v1\/object\/public\/{$this->bucket}\//";
        $path    = preg_replace($pattern, '', parse_url($publicUrl, PHP_URL_PATH));
        if (!$path) return;

        $endpoint = "{$this->url}/storage/v1/object/{$this->bucket}/{$path}";

        \Http::withHeaders([
            'Authorization' => "Bearer {$this->serviceRoleKey}",
        ])->delete($endpoint);
    }
}