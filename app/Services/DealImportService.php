<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\DealImportLog;
use App\Models\SesContract;
use App\Models\WorkRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * DealImportService
 *
 * 販売システム.xlsm（または .xlsx）を読み込み、
 * customers / contacts / deals / ses_contracts / work_records
 * に一括インポートする。
 *
 * 列マッピング（台帳シート、5行目がヘッダー）:
 *   [0]  項番
 *   [1]  氏名（技術者名 → contact.name）
 *   [2]  新・変更者・条件変更等
 *   [3]  担当者（自社営業担当）
 *   [4]  所属
 *   [5]  所属担当者
 *   [6]  Mail
 *   [7]  TEL
 *   [8]  顧客（顧客企業名 → customer.name）
 *   [9]  エンド
 *   [10] 案件名
 *   [11] 顧客側担当者
 *   [12] 顧客側担当者 携帯
 *   [13] 顧客側担当者 TEL
 *   [14] 顧客側担当者 FAX
 *   [15] 入金
 *   [16] 支払+22%
 *   [17] 支払+29%
 *   [18] 営業支援費支払先
 *   [19] 営業支援費
 *   [20] 調整金額
 *   [21] 利益
 *   [22] 利益/29%
 *   [23] 控除単価（顧客側）
 *   [24] 控除時間（顧客側）
 *   [25] 超過単価（顧客側）
 *   [26] 超過時間（顧客側）
 *   [27] 精算単位(分)
 *   [28] 入金サイト
 *   [29] 控除単価（仕入れ側）  ※ 旧: [29] 控除単価（仕入れ側）← 変わらず
 *   [30] 控除時間（仕入れ側）
 *   [31] 超過単価（仕入れ側）
 *   [32] 超過時間（仕入れ側）
 *   [33] 支払サイト
 *   [34] 契約開始
 *   [35] 契約期間開始
 *   [36] 契約期間終了
 *   [37] 期間末（所属）
 *   [38] 現場最寄駅
 *   [39] 勤務表受領日          ← 変わらず
 *   [40] 交通費                ← 変わらず
 *   [41] 欠勤
 *   [42] 有給
 *   [43] 請求書有無
 *   [44] 請求書受領日
 *   [45] 特記事項
 *   [46] 適格請求書発行事業者登録番号
 *   [47] 削除フラグ
 */
class DealImportService
{
    // ── 定数 ─────────────────────────────────────────────

    /** 台帳シート名 */
    private const SHEET_NAME = '台帳';

    /** ヘッダー行のキーワード（この文字列がある行をヘッダーとみなす） */
    private const HEADER_KEYWORD = '項番';

    /** 削除フラグの列インデックス（新列構成） */
    private const COL_DELETE_FLAG = 47;

    // ── プロパティ ────────────────────────────────────────

    private int   $tenantId;
    private int   $importedBy;
    private array $errors   = [];
    private int   $created  = 0;
    private int   $updated  = 0;
    private int   $skipped  = 0;
    private int   $total    = 0;

    public function __construct(int $tenantId, int $importedBy)
    {
        $this->tenantId   = $tenantId;
        $this->importedBy = $importedBy;
    }

    // ── パブリックメソッド ────────────────────────────────

    /**
     * ファイルを読み込んでインポートを実行する
     *
     * @param  string       $filePath  アップロードされたファイルの一時パス
     * @param  string       $filename  元のファイル名（ログ用）
     * @return DealImportLog
     */
    public function import(string $filePath, string $filename): DealImportLog
    {
        // インポートログを作成（processing）
        $log = DealImportLog::create([
            'tenant_id'         => $this->tenantId,
            'imported_by'       => $this->importedBy,
            'original_filename' => $filename,
            'file_type'         => pathinfo($filename, PATHINFO_EXTENSION),
            'status'            => 'processing',
            'started_at'        => now(),
        ]);

        try {
            $rows = $this->loadRows($filePath);
            $this->total = count($rows);

            DB::transaction(function () use ($rows) {
                foreach ($rows as $rowIndex => $row) {
                    try {
                        $this->processRow($row, $rowIndex + 1);
                    } catch (\Throwable $e) {
                        $projectNumber = $row[0] ?? "行{$rowIndex}";
                        $this->errors[] = [
                            'row'            => $rowIndex + 1,
                            'project_number' => $projectNumber,
                            'reason'         => $e->getMessage(),
                        ];
                        Log::warning("DealImport row error", [
                            'row'    => $rowIndex + 1,
                            'error'  => $e->getMessage(),
                        ]);
                    }
                }
            });

            $log->markCompleted([
                'total_rows'     => $this->total,
                'created_count'  => $this->created,
                'updated_count'  => $this->updated,
                'skipped_count'  => $this->skipped,
                'error_count'    => count($this->errors),
            ], $this->errors);

        } catch (\Throwable $e) {
            Log::error("DealImport fatal error", ['error' => $e->getMessage()]);
            $log->markFailed($e->getMessage());
        }

        return $log->fresh();
    }

    // ── プライベートメソッド ──────────────────────────────

    /**
     * Excelファイルを読み込み、アクティブなデータ行の配列を返す
     *
     * @return array<int, array> 各要素が1行分の値配列
     * @throws \Exception シートが見つからない場合
     */
    private function loadRows(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);

        // 「台帳」シートを取得
        if (!$spreadsheet->sheetNameExists(self::SHEET_NAME)) {
            throw new \Exception("シート「" . self::SHEET_NAME . "」が見つかりません。");
        }

        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);
        $allRows = $sheet->toArray(null, true, true, false);

        // ヘッダー行（「項番」がある行）を検索
        $headerRowIndex = null;
        foreach ($allRows as $i => $row) {
            if (isset($row[0]) && $row[0] === self::HEADER_KEYWORD) {
                $headerRowIndex = $i;
                break;
            }
        }

        if ($headerRowIndex === null) {
            throw new \Exception("ヘッダー行（「項番」列）が見つかりません。");
        }

        // ヘッダー行の次からデータ行を取得
        // 削除フラグが 1 の行・項番が空の行は除外
        $dataRows = [];
        for ($i = $headerRowIndex + 1; $i < count($allRows); $i++) {
            $row = $allRows[$i];
            // 項番が空 → 空行とみなしスキップ
            if (empty($row[0])) {
                continue;
            }
            // 削除フラグが 1 → スキップ
            if (isset($row[self::COL_DELETE_FLAG]) && $row[self::COL_DELETE_FLAG] == 1) {
                continue;
            }
            $dataRows[] = $row;
        }

        return $dataRows;
    }

    /**
     * 1行分のデータを処理する
     * customers → contacts → deals → ses_contracts → work_records の順で upsert する
     */
    private function processRow(array $row, int $rowNum): void
    {
        $projectNumber = (int) $row[0];
        $engineerName  = $this->cleanString($row[1] ?? '');
        $customerName  = $this->cleanString($row[8] ?? '');

        // 顧客名が空の場合、案件名 → 氏名 の順でフォールバック
        if (empty($customerName)) {
            $customerName = $this->cleanString($row[10] ?? '')  // 案件名
                         ?? $engineerName;                       // 氏名
        }

        // 必須項目チェック（氏名は必須）
        if (empty($engineerName)) {
            $this->skipped++;
            return;
        }

        // ── 1. Customer（顧客企業）の upsert ──────────────
        $customer = Customer::firstOrCreate(
            [
                'tenant_id'    => $this->tenantId,
                'company_name' => $customerName,
            ],
            [
                'tenant_id'    => $this->tenantId,
                'company_name' => $customerName,
            ]
        );

        // ── 2. Contact（技術者）の upsert ─────────────────
        // メールがある場合は メール＋氏名 の組み合わせをキーにする
        // （同じメールアドレスでも氏名が異なれば別人として扱う）
        // メールがない場合は 氏名＋顧客ID で突合
        $email = $this->cleanString($row[6] ?? '');
        $contactData = [
            'tenant_id'   => $this->tenantId,
            'customer_id' => $customer->id,
            'name'        => $engineerName,
            'email'       => $email,
            'phone'       => $this->cleanString($row[7] ?? ''),
        ];

        $contactKey = !empty($email)
            ? ['tenant_id' => $this->tenantId, 'email' => $email, 'name' => $engineerName]
            : ['tenant_id' => $this->tenantId, 'name'  => $engineerName, 'customer_id' => $customer->id];

        $contact = Contact::updateOrCreate($contactKey, $contactData);

        // ── 3. Deal の upsert ─────────────────────────────
        // project_number があれば突合キーにする
        $dealKey = $projectNumber > 0
            ? ['tenant_id' => $this->tenantId, 'project_number' => $projectNumber]
            : ['tenant_id' => $this->tenantId, 'title' => $this->cleanString($row[10] ?? ''), 'customer_id' => $customer->id];

        $dealData = [
            'tenant_id'           => $this->tenantId,
            'customer_id'         => $customer->id,
            'contact_id'          => $contact->id,
            'title'               => $this->cleanString($row[10] ?? '') ?: "{$engineerName} / {$customerName}",
            'deal_type'           => 'ses',
            'project_number'      => $projectNumber ?: null,
            'end_client'          => $this->cleanString($row[9] ?? ''),
            'nearest_station'     => $this->cleanString($row[38] ?? ''),
            'change_type'         => $this->cleanString($row[2] ?? ''),
            'affiliation'         => $this->cleanString($row[4] ?? ''),
            'affiliation_contact' => $this->cleanString($row[5] ?? ''),
            'sales_person'        => $this->cleanString($row[3] ?? ''),
            'invoice_number'      => $this->cleanString($row[46] ?? ''), // [46] 適格請求書番号
            'client_contact'      => $this->cleanString($row[11] ?? ''),
            'client_mobile'       => $this->cleanString($row[12] ?? ''),
            'client_phone'        => $this->cleanString($row[13] ?? ''),
            'client_fax'          => $this->cleanString($row[14] ?? ''),
            // ステータスは change_type から推測
            'status'              => $this->resolveStatus($row[2] ?? ''),
            'user_id'             => $this->importedBy,
        ];

        $existingDeal = Deal::where($dealKey)->first();

        if ($existingDeal) {
            $existingDeal->update($dealData);
            $deal = $existingDeal;
            $this->updated++;
        } else {
            $deal = Deal::create($dealData);
            $this->created++;
        }

        // ── 4. SesContract の upsert ─────────────────────
        SesContract::updateOrCreate(
            ['deal_id' => $deal->id],
            [
                'tenant_id'                    => $this->tenantId,
                'deal_id'                      => $deal->id,
                // 金額系
                'income_amount'                => $this->toDecimal($row[15] ?? null),
                'billing_plus_22'              => $this->toDecimal($row[16] ?? null),
                'billing_plus_29'              => $this->toDecimal($row[17] ?? null),
                'sales_support_payee'          => $this->cleanString($row[18] ?? ''),
                'sales_support_fee'            => $this->toDecimal($row[19] ?? null),
                'adjustment_amount'            => $this->toDecimal($row[20] ?? null),
                'profit'                       => $this->toDecimal($row[21] ?? null),
                'profit_rate_29'               => $this->toDecimal($row[22] ?? null),
                // 顧客側精算
                'client_deduction_unit_price'  => $this->toDecimal($row[23] ?? null),
                'client_deduction_hours'       => $this->toDecimal($row[24] ?? null),
                'client_overtime_unit_price'   => $this->toDecimal($row[25] ?? null),
                'client_overtime_hours'        => $this->toDecimal($row[26] ?? null),
                'settlement_unit_minutes'      => $this->toInt($row[27] ?? null),
                'payment_site'                 => $this->toInt($row[28] ?? null),
                // 仕入れ側精算
                'vendor_deduction_unit_price'  => $this->toDecimal($row[29] ?? null),
                'vendor_deduction_hours'       => $this->toDecimal($row[30] ?? null),
                'vendor_overtime_unit_price'   => $this->toDecimal($row[31] ?? null),
                'vendor_overtime_hours'        => $this->toDecimal($row[32] ?? null),
                'vendor_payment_site'          => $this->toInt($row[33] ?? null),
                // 契約期間
                'contract_start'               => $this->toDate($row[34] ?? null),
                'contract_period_start'        => $this->toDate($row[35] ?? null),
                'contract_period_end'          => $this->toDate($row[36] ?? null),
                'affiliation_period_end'       => $this->cleanString($row[37] ?? ''),
            ]
        );

        // ── 5. WorkRecord の upsert（勤務表・請求書情報）────
        // 契約期間終了月を year_month として使用。なければ現在月
        $contractEnd = $this->toDate($row[36] ?? null);
        $yearMonth   = $contractEnd
            ? Carbon::parse($contractEnd)->format('Y-m')
            : now()->format('Y-m');

        // 勤務表受領日 or 請求書受領日があるときだけ保存
        $timesheetDate = $this->toDate($row[39] ?? null);  // 変わらず
        $invoiceDate   = $this->toDate($row[44] ?? null);  // [44] 請求書受領日
        $notes         = $this->cleanString($row[45] ?? ''); // [45] 特記事項

        if ($timesheetDate || $invoiceDate) {
            WorkRecord::updateOrCreate(
                ['deal_id' => $deal->id, 'year_month' => $yearMonth],
                [
                    'tenant_id'               => $this->tenantId,
                    'timesheet_received_date' => $timesheetDate,
                    'transportation_fee'      => $this->toDecimal($row[40] ?? null), // 変わらず
                    'invoice_received_date'   => $invoiceDate,
                    'notes'                   => $notes,
                ]
            );
        }
    }

    // ── 型変換ヘルパー ────────────────────────────────────

    /**
     * 文字列クリーニング
     * - 全角スペース → 除去
     * - '-' や空白のみ → null に変換
     */
    private function cleanString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        // 全角スペースを除去
        $value = str_replace("\u{3000}", '', trim($value));
        // ハイフン1文字・空文字は null
        if ($value === '-' || $value === '') {
            return null;
        }
        return $value;
    }

    /**
     * Excel の日付（シリアル値 or DateTime or 文字列）を Y-m-d 文字列に変換
     */
    private function toDate(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }

        // PhpSpreadsheet が DateTime オブジェクトで返してくる場合
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d');
        }

        // Excelのシリアル値（数値）の場合
        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        // 文字列の場合（"2026-03-31" 等）
        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 数値文字列・'-' を decimal に変換
     * '-' や非数値は null を返す
     */
    private function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }
        // カンマ区切り（例: 620,000）を除去
        if (is_string($value)) {
            $value = str_replace(',', '', trim($value));
        }
        // 数値以外の文字が含まれていたら null
        if (!is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    /**
     * 数値を int に変換
     */
    private function toInt(mixed $value): ?int
    {
        $decimal = $this->toDecimal($value);
        return $decimal !== null ? (int) $decimal : null;
    }

    /**
     * change_type（新・変更者・条件変更等）の値から deals.status を推測する
     */
    private function resolveStatus(?string $changeType): string
    {
        if ($changeType === null) {
            return 'active';
        }

        return match (true) {
            str_contains($changeType, '退場') => '期限切れ',
            str_contains($changeType, '新規') => '新規',
            default                           => '稼働中',
        };
    }
}
