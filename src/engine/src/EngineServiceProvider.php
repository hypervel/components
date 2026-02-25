<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use Hypervel\Contracts\Engine\Http\ServerFactoryInterface;
use Hypervel\Contracts\Engine\Http\V2\ClientFactoryInterface;
use Hypervel\Contracts\Engine\Socket\SocketFactoryInterface;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Http\ServerFactory;
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
        $this->app->singleton(SocketFactoryInterface::class, fn () => new SocketFactory());

        $this->app->singleton(ServerFactoryInterface::class, fn ($app) => new ServerFactory(
            $app->make(StdoutLoggerInterface::class)
        ));

        $this->app->singleton(ClientFactoryInterface::class, fn () => new ClientFactory());
    }
}
