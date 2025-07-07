<?php

declare(strict_types=1);

namespace Hypervel\Permission;

use Hypervel\Permission\Contracts\Factory;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                Factory::class => PermissionManager::class,
            ],
        ];
    }
}
