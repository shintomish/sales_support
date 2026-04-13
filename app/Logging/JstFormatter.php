<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractHandler;

class JstFormatter
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        $monolog->setTimezone(new \DateTimeZone('Asia/Tokyo'));
    }
}
