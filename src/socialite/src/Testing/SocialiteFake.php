<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Testing;

use Closure;
use Hypervel\Socialite\Contracts\Factory;
use Hypervel\Socialite\Contracts\User as UserContract;

class SocialiteFake implements Factory
{
    /**
     * The fake provider instances.
     *
     * @var array<string, FakeProvider>
     */
    protected array $providers = [];

    /**
     * Create a new Socialite fake instance.
     */
    public function __construct(
        protected Factory $factory
    ) {
    }

    /**
     * Get a provider implementation.
     */
    public function driver(?string $driver = null): mixed
    {
        return $this->providers[$driver] ?? $this->factory->driver($driver);
    }

    /**
     * Register a fake user for the given driver.
     */
    public function fake(string $driver, UserContract|Closure|null $user = null): static
    {
        $resolver = function () use ($driver) {
            return $this->factory->driver($driver);
        };

        $this->providers[$driver] = new FakeProvider($driver, $resolver, $user);

        return $this;
    }
}
