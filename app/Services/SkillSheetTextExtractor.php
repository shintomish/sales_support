<?php

namespace App\Services;

use App\Models\EmailAttachment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;

/**
 * 技術者メール添付のスキルシート (Excel/PDF/Word) からテキストを抽出する。
 * 抽出結果は engineer_mail_sources.parsed_skill_sheet_text に保存され、
 * RequirementMatchingService Stage 2 (Claude 判定) の入力に使われる (docs/480 §3.3)。
 *
 * 設計方針:
 * - 1 EMS あたり 1 つの「最も skill sheet らしい」添付を選ぶ
 * - サイズ上限 (3MB) 超は skip (Excel 大容量問題を回避)
 * - 抽出失敗は warning ログ + null 返却 (取込全体は止めない)
 * - 抽出テキストは 30,000 文字 で truncate (Claude 入力上限ガード)
 */
class SkillSheetTextExtractor
{
    private const MAX_FILE_SIZE = 3 * 1024 * 1024; // 3MB
    private const MAX_TEXT_LEN  = 30000;

    /** ファイル拡張子 → 抽出メソッド */
    private const SUPPORTED = [
        'xls'  => 'extractExcel',
        'xlsx' => 'extractExcel',
        'xlsm' => 'extractExcel',
        'pdf'  => 'extractPdf',
        'doc'  => 'extractWord',
        'docx' => 'extractWord',
    ];

    /** 1 メールの添付一覧から最有力のスキルシートを選んで抽出。無ければ null。 */
    public function extractFromAttachments(iterable $attachments): ?string
    {
        $candidate = $this->pickBestCandidate($attachments);
        if (!$candidate) return null;
        return $this->extractOne($candidate);
    }

    /** 単一 attachment からの抽出。テキスト取れなければ null。 */
    public function extractOne(EmailAttachment $att): ?string
    {
        if ($att->size && $att->size > self::MAX_FILE_SIZE) {
            Log::info('[SkillSheetExtractor] size limit skip', ['filename' => $att->filename, 'size' => $att->size]);
            return null;
        }

        $ext = $this->extOf($att);
        if (!isset(self::SUPPORTED[$ext])) return null;

        $binary = $this->fetchBinary($att);
        if (!$binary) return null;

        try {
            $method = self::SUPPORTED[$ext];
            $text   = $this->{$method}($binary, $att->filename);
        } catch (\Throwable $e) {
            Log::warning('[SkillSheetExtractor] extract failed', [
                'filename' => $att->filename,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }

        if (!$text) return null;
        return mb_substr($this->normalize($text), 0, self::MAX_TEXT_LEN);
    }

    /** 「スキルシート」「skillsheet」「履歴」を含むファイル名 を最優先で選定。 */
    private function pickBestCandidate(iterable $attachments): ?EmailAttachment
    {
        $best = null;
        $bestScore = 0;
        foreach ($attachments as $att) {
            $ext = $this->extOf($att);
            if (!isset(self::SUPPORTED[$ext])) continue;
            if ($att->size && $att->size > self::MAX_FILE_SIZE) continue;

            $score = 1;
            $name = mb_strtolower($att->filename ?? '');
            if (str_contains($name, 'skill') || str_contains($name, 'スキル') || str_contains($name, 'シート'))    $score += 5;
            if (str_contains($name, '履歴'))                                                                       $score += 3;
            if (str_contains($name, '経歴'))                                                                       $score += 3;
            // Excel が職務経歴書として最も一般的
            if (in_array($ext, ['xlsx', 'xls', 'xlsm'], true))                                                      $score += 1;
            // 小さい順を優先 (パース失敗リスク減)
            if ($att->size && $att->size < 500_000)                                                                 $score += 1;

            if ($score > $bestScore) {
                $best = $att;
                $bestScore = $score;
            }
        }
        return $best;
    }

    private function extOf(EmailAttachment $att): string
    {
        return strtolower(pathinfo($att->filename ?? '', PATHINFO_EXTENSION));
    }

    /** Supabase Storage からダウンロード (storage_path がある場合のみ)。 */
    private function fetchBinary(EmailAttachment $att): ?string
    {
        if (!$att->storage_path) return null;

        $supabaseUrl = config('services.supabase.url');
        $serviceKey  = config('services.supabase.service_role_key');
        $bucket      = config('services.supabase.bucket');

        // storage_path 形式は public URL またはパス
        $pattern = "/storage\/v1\/object\/public\/{$bucket}\//";
        $path    = preg_replace($pattern, '', parse_url($att->storage_path, PHP_URL_PATH) ?: $att->storage_path);

        try {
            $res = Http::timeout(30)
                ->withHeaders(['Authorization' => "Bearer {$serviceKey}"])
                ->get("{$supabaseUrl}/storage/v1/object/{$bucket}/{$path}");
            return $res->successful() ? $res->body() : null;
        } catch (\Throwable $e) {
            Log::warning('[SkillSheetExtractor] storage fetch failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function extractExcel(string $binary, string $filename): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'skillsheet_') . '.' . pathinfo($filename, PATHINFO_EXTENSION);
        file_put_contents($tmp, $binary);
        try {
            $reader = SpreadsheetIOFactory::createReaderForFile($tmp);
            $reader->setReadDataOnly(true);
            $ss = $reader->load($tmp);
            $out = [];
            foreach ($ss->getAllSheets() as $sheet) {
                $out[] = '【シート: ' . $sheet->getTitle() . '】';
                foreach ($sheet->toArray(null, true, true, false) as $row) {
                    $line = trim(implode("\t", array_map(fn($c) => (string) ($c ?? ''), $row)));
                    if ($line !== '') $out[] = $line;
                }
            }
            return implode("\n", $out);
        } finally {
            @unlink($tmp);
        }
    }

    private function extractPdf(string $binary, string $filename): ?string
    {
        $parser = new PdfParser();
        $pdf = $parser->parseContent($binary);
        return $pdf->getText();
    }

    private function extractWord(string $binary, string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'doc') {
            // PhpWord は .doc のテキスト抽出をサポートしない (.docx のみ)。skip。
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'skillsheet_') . '.docx';
        file_put_contents($tmp, $binary);
        try {
            $phpWord = PhpWordIOFactory::load($tmp);
            $out = [];
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $el) {
                    if (method_exists($el, 'getText')) {
                        $out[] = (string) $el->getText();
                    } elseif (method_exists($el, 'getElements')) {
                        foreach ($el->getElements() as $inner) {
                            if (method_exists($inner, 'getText')) $out[] = (string) $inner->getText();
                        }
                    }
                }
            }
            return implode("\n", array_filter($out, fn($s) => trim($s) !== ''));
        } finally {
            @unlink($tmp);
        }
    }

    /** 連続空白・タブを 1 個に / 連続改行を 2 個までに圧縮 (token 節約)。 */
    private function normalize(string $text): string
    {
        $text = preg_replace('/[ \t\x{3000}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }
}
