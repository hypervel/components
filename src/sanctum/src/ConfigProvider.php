<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'listeners' => [
                \Hypervel\Sanctum\Listeners\RegisterSanctumGuard::class,
            ],
        ];
    }
}