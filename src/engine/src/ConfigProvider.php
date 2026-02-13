<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use Hypervel\Contracts\Engine\Http\ServerFactoryInterface;
use Hypervel\Contracts\Engine\Http\V2\ClientFactoryInterface;
use Hypervel\Contracts\Engine\Socket\SocketFactoryInterface;
use Hypervel\Engine\Http\ServerFactory;
use Hypervel\Engine\Http\V2\ClientFactory;
use Hypervel\Engine\Socket\SocketFactory;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                SocketFactoryInterface::class => SocketFactory::class,
                ServerFactoryInterface::class => ServerFactory::class,
                ClientFactoryInterface::class => ClientFactory::class,
            ],
        ];
    }
}
