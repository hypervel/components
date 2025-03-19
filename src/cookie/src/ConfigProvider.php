<?php

declare(strict_types=1);

namespace Hypervel\Cookie;

use Hypervel\Cookie\Contracts\Cookie as CookieContract;

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
