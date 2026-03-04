<?php

declare(strict_types=1);

namespace Hypervel\Serializer;

use Hypervel\Contracts\Serializer\NormalizerInterface;
use Hypervel\Support\ServiceProvider;
use Symfony\Component\Serializer\Serializer;

class SerializerServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(Serializer::class, fn ($app) => $app->make(SerializerFactory::class)());

        $this->app->singleton(NormalizerInterface::class, SimpleNormalizer::class);
    }
}
