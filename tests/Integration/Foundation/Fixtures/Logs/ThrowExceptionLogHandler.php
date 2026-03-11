<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Fixtures\Logs;

use Exception;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

class ThrowExceptionLogHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        throw new Exception('Thrown inside ThrowExceptionLogHandler');
    }
}
