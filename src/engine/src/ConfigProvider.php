<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use Hypervel\Contracts\Engine\Socket\SocketFactoryInterface;
use Hypervel\Engine\Socket\SocketFactory;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                SocketFactoryInterface::class => SocketFactory::class,
            ],
        ];
    }
}
