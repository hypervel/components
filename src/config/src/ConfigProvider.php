<?php

declare(strict_types=1);

namespace Hypervel\Config;

use Hyperf\Contract\ConfigInterface;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                ConfigInterface::class => ConfigFactory::class,
            ],
        ];
    }
}
