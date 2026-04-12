<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite\Fixtures;

use GuzzleHttp\Client;
use Hypervel\Socialite\AbstractProvider;

/**
 * Minimal stub extending the generic base class (not OAuth2).
 *
 * Used to test that the protocol-agnostic infrastructure works
 * independently of any protocol-specific subclass.
 */
class GenericTestProviderStub extends AbstractProvider
{
    /**
     * Expose getConfig() publicly for testing.
     */
    public function getProviderConfig(?string $key = null, mixed $default = null): mixed
    {
        return $this->getConfig($key, $default);
    }

    /**
     * Expose usesState() publicly for testing.
     */
    public function providerUsesState(): bool
    {
        return $this->usesState();
    }

    /**
     * Expose getHttpClient() publicly for testing.
     */
    public function getProviderHttpClient(): Client
    {
        return $this->getHttpClient();
    }
}
