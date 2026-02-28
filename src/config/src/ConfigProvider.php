<?php

declare(strict_types=1);

namespace Hypervel\Config;

use Hypervel\Contracts\Config\Repository;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                Repository::class => ConfigFactory::class,
            ],
        ];
    }
}
