<?php

declare(strict_types=1);

namespace Hypervel\Config;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Config\Contracts\Repository as ConfigContract;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                ConfigContract::class => ConfigFactory::class,
                ConfigInterface::class => ConfigFactory::class,
            ],
        ];
    }
}
