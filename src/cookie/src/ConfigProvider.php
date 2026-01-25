<?php

declare(strict_types=1);

namespace Hypervel\Cookie;

use Hypervel\Contracts\Cookie\Cookie as CookieContract;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                CookieContract::class => CookieManager::class,
            ],
        ];
    }
}
