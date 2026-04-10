<?php

namespace App\Services;

use App\Models\DeliveryAddress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DeliveryAddressImportService
{
    public function __construct(
        private readonly int $tenantId,
    ) {}

    /**
     * CSVファイルをインポートする
     *
     * スキップ条件:
     *   - Name が空白
     *   - email が有効でない（形式不正・空）
     *
     * 既存レコード（tenant_id + email が一致）は上書き更新する。
     *
     * @return array{imported: int, skipped: int, total: int, skipped_list: array}
     */
    public function progressKey(): string
    {
        return "delivery_import_{$this->tenantId}";
    }

    public function import(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("CSVファイルを開けませんでした: {$filePath}");
        }

        // BOM除去
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // 総行数を先読みしてキャッシュ初期化
        $allRows = [];
        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $allRows[] = $row;
        }
        fclose($handle);

        $totalRows = max(0, count($allRows) - 1); // ヘッダー除く
        $cacheKey  = $this->progressKey();
        Cache::put($cacheKey, ['current' => 0, 'total' => $totalRows, 'done' => false], 300);

        $imported    = 0;
        $skipped     = 0;
        $total       = 0;
        $skippedList = [];
        $header      = null;

        foreach ($allRows as $row) {
            // ヘッダー行を取得
            if ($header === null) {
                $header = array_map('trim', $row);
                continue;
            }

            $total++;
            $data = $this->mapRow($header, $row);

            // 50行ごとに進捗更新
            if ($total % 50 === 0) {
                Cache::put($cacheKey, ['current' => $total, 'total' => $totalRows, 'done' => false], 300);
            }

            // --- スキップ判定 ---
            if (empty(trim((string) ($data['name'] ?? '')))) {
                $skipped++;
                $skippedList[] = [
                    'row'    => $total,
                    'email'  => $data['email'] ?: '(空)',
                    'name'   => '(空)',
                    'reason' => 'Name が空白',
                ];
                Log::debug("[DeliveryImport] skip: name empty row={$total}");
                continue;
            }

            $emailError = $this->validateEmail($data['email'] ?? '');
            if ($emailError !== null) {
                $skipped++;
                $skippedList[] = [
                    'row'    => $total,
                    'email'  => $data['email'] ?: '(空)',
                    'name'   => $data['name'],
                    'reason' => $emailError,
                ];
                Log::debug("[DeliveryImport] skip: {$emailError} email={$data['email']} row={$total}");
                continue;
            }

            // --- upsert（tenant_id + email でユニーク） ---
            DeliveryAddress::updateOrCreate(
                [
                    'tenant_id' => $this->tenantId,
                    'email'     => mb_strtolower(trim($data['email'])),
                ],
                [
                    'name'       => trim($data['name']),
                    'zip_code'   => $data['zip_code']   ?: null,
                    'prefecture' => $data['prefecture']  ?: null,
                    'address'    => $data['address']     ?: null,
                    'tel'        => $data['tel']         ?: null,
                    'occupation' => $data['occupation']  ?: null,
                    'is_active'  => true,
                ]
            );

            $imported++;
        }

        // 完了をキャッシュに書き込む
        Cache::put($cacheKey, ['current' => $total, 'total' => $totalRows, 'done' => true], 60);

        Log::info("[DeliveryImport] tenant_id={$this->tenantId} total={$total} imported={$imported} skipped={$skipped}");

        return [
            'total'        => $total,
            'imported'     => $imported,
            'skipped'      => $skipped,
            'skipped_list' => $skippedList,
        ];
    }

    /**
     * ヘッダー行をもとに行データをマッピングする
     * CSVカラム: e-mail, Name, ZipCode, Prefecture, Address, Tel, Birthday, Occupation
     */
    private function mapRow(array $header, array $row): array
    {
        // 列数が足りない場合は空文字で補完
        $row = array_pad($row, count($header), '');
        $map = array_combine($header, $row);

        return [
            'email'      => $this->trimAll($map['e-mail']    ?? $map['email']      ?? ''),
            'name'       => $this->trimAll($map['Name']       ?? $map['name']       ?? ''),
            'zip_code'   => trim($map['ZipCode']     ?? $map['zip_code']   ?? ''),
            'prefecture' => trim($map['Prefecture']  ?? $map['prefecture'] ?? ''),
            'address'    => trim($map['Address']     ?? $map['address']    ?? ''),
            'tel'        => trim($map['Tel']         ?? $map['tel']        ?? ''),
            'occupation' => trim($map['Occupation']  ?? $map['occupation'] ?? ''),
        ];
    }

    /**
     * 半角・全角スペース／タブ／改行をすべて除去する
     */
    private function trimAll(string $value): string
    {
        // 全角スペース（U+3000）・半角スペース・タブ・改行を除去
        return trim(preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $value));
    }

    /**
     * メールアドレスを検証し、問題があれば理由を返す（null=正常）
     */
    private function validateEmail(string $email): ?string
    {
        if (empty($email)) {
            return 'メールアドレスが空';
        }

        // 全角文字が含まれているか確認
        if (preg_match('/[^\x00-\x7F]/', $email)) {
            // @が全角
            if (str_contains($email, '＠')) {
                return '＠（全角アットマーク）が含まれている';
            }
            // ドットが全角
            if (str_contains($email, '．')) {
                return '．（全角ドット）が含まれている';
            }
            return '全角文字が含まれている';
        }

        // @が含まれていない
        if (!str_contains($email, '@')) {
            return '@ が含まれていない';
        }

        // @が複数
        if (substr_count($email, '@') > 1) {
            return '@ が複数含まれている';
        }

        // ローカル部またはドメイン部が空
        [$local, $domain] = explode('@', $email, 2);
        if (empty($local)) {
            return '@ の前が空';
        }
        if (empty($domain)) {
            return '@ の後が空';
        }

        // ドメインにドットがない
        if (!str_contains($domain, '.')) {
            return 'ドメインにドット（.）がない';
        }

        // スペースが含まれている
        if (str_contains($email, ' ')) {
            return 'スペースが含まれている';
        }

        // RFC形式チェック
        $validator = Validator::make(
            ['email' => $email],
            ['email' => 'required|email:rfc']
        );

        if ($validator->fails()) {
            return 'メールアドレスの形式が不正';
        }

        return null;
    }
}
