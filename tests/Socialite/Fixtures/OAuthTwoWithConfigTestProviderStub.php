<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite\Fixtures;

class OAuthTwoWithConfigTestProviderStub extends OAuthTwoTestProviderStub
{
    /**
     * Expose getConfig() publicly for testing.
     */
    public function getProviderConfig(?string $key = null, mixed $default = null): mixed
    {
        return $this->getConfig($key, $default);
    }
}
