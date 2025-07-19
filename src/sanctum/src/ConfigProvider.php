<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hypervel\Sanctum\Console\Commands\PruneExpired;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'commands' => [
                PruneExpired::class,
            ],
        ];
    }
}
