<?php

declare(strict_types=1);

namespace Hypervel\Log\Handlers;

use Hypervel\Log\Handlers\Concerns\PerformsSafeStreamOperations;
use Monolog\Handler\StreamHandler as MonologStreamHandler;
use Monolog\LogRecord;
use Override;

class StreamHandler extends MonologStreamHandler
{
    use PerformsSafeStreamOperations;

    #[Override]
    public function close(): void
    {
        $this->closeStreamSafely();
    }

    #[Override]
    protected function write(LogRecord $record): void
    {
        $this->writeStreamSafely($record);
    }
}
