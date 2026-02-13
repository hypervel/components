<?php

declare(strict_types=1);

namespace Hypervel\Config;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Contracts\Config\Repository;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                ConfigInterface::class => ConfigFactory::class,
                Repository::class => ConfigFactory::class,
            ],
        ];
    }
}
