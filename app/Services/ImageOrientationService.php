<?php

namespace App\Services;

/**
 * 名刺画像の向き正規化サービス。
 *
 * 1. EXIF Orientation タグを参照して画像本体を正立に補正
 * 2. 補正後に縦長 (height > width) なら 90度 回転して横向きに揃える
 *
 * 名刺は基本的に横長で扱うため、スマホ縦撮り画像でも横向きに統一する。
 * 出力は JPEG バイナリ。
 */
class ImageOrientationService
{
    /**
     * 画像バイナリを受け取り、正立 + 横向きの JPEG バイナリを返す。
     * GD で扱えない形式や処理失敗時は元バイナリをそのまま返す。
     */
    public function normalize(string $binary): string
    {
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return $binary;
        }

        try {
            // 1. EXIF Orientation 補正
            $image = $this->autoRotateByExif($image, $binary);

            // 2. 縦長なら 90度 回転して横向きに
            if (imagesy($image) > imagesx($image)) {
                $rotated = imagerotate($image, 90, 0);
                if ($rotated !== false) {
                    imagedestroy($image);
                    $image = $rotated;
                }
            }

            ob_start();
            imagejpeg($image, null, 90);
            $output = ob_get_clean();
            return $output !== false ? $output : $binary;
        } finally {
            if (is_resource($image) || $image instanceof \GdImage) {
                imagedestroy($image);
            }
        }
    }

    /**
     * EXIF Orientation に従って画像を正立させる。
     * 1=正立, 3=180度, 6=右90度, 8=左90度 のみ対応 (一般的な4種)
     */
    private function autoRotateByExif(\GdImage $image, string $binary): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        // exif_read_data はファイル/ストリーム必須なので一時ストリームに包む
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $binary);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);

        $orientation = $exif['Orientation'] ?? 1;

        $angle = match ($orientation) {
            3       => 180,
            6       => -90,
            8       => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);
        return $rotated;
    }
}
