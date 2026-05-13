<?php

namespace App\Logging;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;

/**
 * RotatingFileHandler の JST 版
 *
 * 標準の RotatingFileHandler は date() でファイル名の日付を組み立てるため、
 * PHP のデフォルトタイムゾーン (UTC) に依存する。
 * その結果、JST 0:00〜9:00 に書かれたログが UTC 前日のファイルに記録されてしまう。
 *
 * 本クラスは getTimedFilename() と nextRotation の計算を JST で行うことで、
 * ファイル名の日付・ローテーションタイミングを日本時間基準に揃える。
 */
class JstRotatingFileHandler extends RotatingFileHandler
{
    private const TIMEZONE = 'Asia/Tokyo';

    public function __construct(
        string $filename,
        int $maxFiles = 0,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        ?int $filePermission = null,
        bool $useLocking = false,
    ) {
        parent::__construct($filename, $maxFiles, $level, $bubble, $filePermission, $useLocking);

        // 親クラスは UTC 0:00 を起点にしているので JST 0:00 に上書き
        $this->nextRotation = (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))
            ->setTime(0, 0)
            ->add(new \DateInterval('P1D'));
    }

    protected function getTimedFilename(): string
    {
        $fileInfo = pathinfo($this->filename);
        $datePart = (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))
            ->format($this->dateFormat);

        $timedFilename = str_replace(
            ['{filename}', '{date}'],
            [$fileInfo['filename'], $datePart],
            ($fileInfo['dirname'] ?? '') . '/' . $this->filenameFormat
        );

        if (!empty($fileInfo['extension'])) {
            $timedFilename .= '.' . $fileInfo['extension'];
        }

        return $timedFilename;
    }
}
