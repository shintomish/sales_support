<?php

namespace App\Services;

/**
 * 1枚の画像に複数名刺が並んでいる場合に矩形を推定して分割するサービス。
 *
 * 高速・依存なしで動かすため、画像のアスペクト比による単純ヒューリスティックで分割。
 * 標準的な日本の名刺は 91x55mm ≒ アスペクト比 1.65。
 *
 * 例:
 * - 1枚 (1.65 付近): 分割しない
 * - 横に2枚並べ (3.30 付近): 横に2分割
 * - 縦に2枚並べ (0.83 付近): 縦に2分割
 * - 2x2 グリッド (1.65 付近): 検出不可。1枚として扱う (将来Vision API で改善)
 *
 * 出力: JPEG バイナリの配列。1要素なら分割なし。
 */
class MultiCardSplitterService
{
    /** 標準名刺アスペクト比 (横/縦) */
    private const CARD_ASPECT = 1.65;
    /** ±この範囲内なら 1枚扱い */
    private const TOLERANCE = 0.35;
    /** 最大分割数 (安全弁) */
    private const MAX_SPLITS = 6;

    /**
     * 画像バイナリから複数名刺を切り出す。1枚と判定された場合は元画像を返す。
     * @return string[] JPEG バイナリ配列
     */
    public function split(string $binary): array
    {
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return [$binary];
        }

        try {
            $w = imagesx($image);
            $h = imagesy($image);
            $aspect = $w / $h;

            // 1枚と判定 (アスペクト比が標準名刺±tolerance)
            if (abs($aspect - self::CARD_ASPECT) <= self::TOLERANCE) {
                return [$binary];
            }

            // 横に N枚並んでいる (横長)
            if ($aspect > self::CARD_ASPECT + self::TOLERANCE) {
                $cols = min(self::MAX_SPLITS, max(2, (int) round($aspect / self::CARD_ASPECT)));
                return $this->cropGrid($image, $cols, 1);
            }

            // 縦に N枚並んでいる (縦長)
            $invAspect = $h / $w;
            if ($invAspect > self::CARD_ASPECT + self::TOLERANCE) {
                $rows = min(self::MAX_SPLITS, max(2, (int) round($invAspect / self::CARD_ASPECT)));
                return $this->cropGrid($image, 1, $rows);
            }

            // 想定外 (正方形に近い等) は分割せず元画像を返す
            return [$binary];
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * 画像を cols x rows のグリッドに分割し、各セルを JPEG バイナリで返す。
     */
    private function cropGrid(\GdImage $image, int $cols, int $rows): array
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $cellW = (int) floor($w / $cols);
        $cellH = (int) floor($h / $rows);

        $results = [];
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $cell = imagecreatetruecolor($cellW, $cellH);
                imagecopy($cell, $image, 0, 0, $c * $cellW, $r * $cellH, $cellW, $cellH);

                ob_start();
                imagejpeg($cell, null, 90);
                $bin = ob_get_clean();
                imagedestroy($cell);

                if ($bin !== false) {
                    $results[] = $bin;
                }
            }
        }
        return $results;
    }
}
