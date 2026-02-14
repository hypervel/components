<?php

declare(strict_types=1);

namespace Hypervel\ExceptionHandler;

use Hypervel\ExceptionHandler\Formatter\DefaultFormatter;
use Hypervel\ExceptionHandler\Formatter\FormatterInterface;
use Hypervel\ExceptionHandler\Listener\ExceptionHandlerListener;

class ConfigProvider
{
    /**
     * Get the package configuration.
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                FormatterInterface::class => DefaultFormatter::class,
            ],
            'listeners' => [
                ExceptionHandlerListener::class,
            ],
        ];
    }
}
