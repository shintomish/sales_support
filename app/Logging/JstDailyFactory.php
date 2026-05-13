<?php

namespace App\Logging;

use Monolog\Logger;

/**
 * 日本時間基準で daily ローテーションする Logger を生成するファクトリ
 *
 * config/logging.php で以下のように利用:
 *
 * 'channel_name' => [
 *     'driver' => 'custom',
 *     'via'    => \App\Logging\JstDailyFactory::class,
 *     'path'   => storage_path('logs/xxx.log'),
 *     'days'   => 365,
 *     'level'  => 'info',
 * ],
 */
class JstDailyFactory
{
    public function __invoke(array $config): Logger
    {
        $handler = new JstRotatingFileHandler(
            filename:   $config['path'],
            maxFiles:   $config['days'] ?? 7,
            level:      $config['level'] ?? 'debug',
            bubble:     $config['bubble'] ?? true,
            filePermission: $config['permission'] ?? null,
            useLocking: $config['locking'] ?? false,
        );

        $logger = new Logger($config['name'] ?? 'jst-daily');
        $logger->setTimezone(new \DateTimeZone('Asia/Tokyo'));
        $logger->pushHandler($handler);

        return $logger;
    }
}
