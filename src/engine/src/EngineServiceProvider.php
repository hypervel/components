<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use Hypervel\Contracts\Engine\Http\V2\ClientFactoryInterface;
use Hypervel\Contracts\Engine\Socket\SocketFactoryInterface;
use Hypervel\Engine\Http\V2\ClientFactory;
use Hypervel\Engine\Socket\SocketFactory;
use Hypervel\Support\ServiceProvider;

class EngineServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(SocketFactoryInterface::class, SocketFactory::class);

        $this->app->singleton(ClientFactoryInterface::class, ClientFactory::class);
    }
}
