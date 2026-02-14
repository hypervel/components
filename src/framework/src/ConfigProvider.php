<?php

declare(strict_types=1);

namespace Hypervel\Framework;

use Hyperf\Contract\StdoutLoggerInterface;
use Hypervel\Framework\Logger\StdoutLogger;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                StdoutLoggerInterface::class => StdoutLogger::class,
            ],
        ];
    }
}
