<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

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
     *
     * @param string|null $baseName 指定した場合、ファイル名のベース部分として使用（空白除去済み）
     */
    public function upload(UploadedFile $file, string $folder = 'default', ?string $baseName = null): string
    {
        $ext       = $file->getClientOriginalExtension();
        $timestamp = now()->format('Ymd_His');

        $rawName = $baseName ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        // 空白除去 → 記号をアンダースコアに変換 → 非ASCII文字を除去
        $safeName = str_replace([' ', '　'], '', $rawName);
        $safeName = preg_replace('/[^\w\-\.]/u', '_', $safeName);   // 記号→_
        $safeName = preg_replace('/[^\x00-\x7F]/u', '', $safeName); // 非ASCIIを除去
        $safeName = preg_replace('/_+/', '_', trim($safeName, '_')); // 連続_を整理

        // 非ASCII文字のみで構成されていた場合（日本語名など）→ ハッシュで代替
        if ($safeName === '') {
            $safeName = substr(md5($rawName), 0, 8);
        }

        $filename = $folder . '/' . $safeName . '_' . $timestamp . '.' . $ext;
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
     * バイナリデータを Supabase Storage にアップロードして公開URLを返す
     */
    public function uploadBinary(string $binary, string $filename, string $mimeType): string
    {
        $endpoint = "{$this->url}/storage/v1/object/{$this->bucket}/{$filename}";

        $response = \Http::withHeaders([
            'Authorization' => "Bearer {$this->serviceRoleKey}",
            'Content-Type'  => $mimeType,
            'x-upsert'      => 'true',
        ])->withBody($binary, $mimeType)->post($endpoint);

        if ($response->failed()) {
            throw new \Exception('Supabase Storage upload failed: ' . $response->body());
        }

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