<?php

declare(strict_types=1);

namespace Hypervel\Socialite;

use Hypervel\Socialite\Contracts\Factory;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                Factory::class => SocialiteManager::class,
            ],
        ];
    }
}
