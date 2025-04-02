<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hypervel\ObjectPool\Contracts\Factory;
use Hypervel\ObjectPool\Contracts\Recycler;
use Hypervel\ObjectPool\Listeners\StartRecycler;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                Factory::class => PoolManager::class,
                Recycler::class => ObjectRecycler::class,
            ],
            'listeners' => [
                StartRecycler::class,
            ],
        ];
    }
}
