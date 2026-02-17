<?php

declare(strict_types=1);

namespace Hypervel\Framework;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
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
