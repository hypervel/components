<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Contracts\Validation\UncompromisedVerifier;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\HttpClient\Factory as HttpFactory;
use Hypervel\Support\ServiceProvider;

class ValidationServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerPresenceVerifier();
        $this->registerUncompromisedVerifier();
        $this->registerValidationFactory();
    }

    /**
     * Register the validation factory.
     */
    protected function registerValidationFactory(): void
    {
        $this->app->singleton('validator', function ($app) {
            $validator = new Factory($app['translator'], $app);

            // The validation presence verifier is responsible for determining the existence of
            // values in a given data collection which is typically a relational database or
            // other persistent data stores. It is used to check for "uniqueness" as well.
            if (isset($app['db'], $app['validation.presence'])) {
                $validator->setPresenceVerifier($app['validation.presence']);
            }

            return $validator;
        });
    }

    /**
     * Register the database presence verifier.
     */
    protected function registerPresenceVerifier(): void
    {
        $this->app->singleton('validation.presence', function ($app) {
            return new DatabasePresenceVerifier($app->make(ConnectionResolverInterface::class));
        });
    }

    /**
     * Register the uncompromised password verifier.
     */
    protected function registerUncompromisedVerifier(): void
    {
        $this->app->singleton(UncompromisedVerifier::class, function ($app) {
            return new NotPwnedVerifier($app->make(HttpFactory::class));
        });
    }
}
