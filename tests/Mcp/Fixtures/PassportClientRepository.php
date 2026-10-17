<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Passport\Client;
use Hypervel\Passport\Passport;

class PassportClientRepository
{
    /**
     * Store a client with a predictable identifier for registration tests.
     */
    public function createAuthorizationCodeGrantClient(string $name, array $redirectUris, bool $confidential = true, ?Authenticatable $user = null, bool $enableDeviceFlow = false): Client
    {
        return Passport::client()->newQuery()->forceCreate([
            'id' => 'test-client-id',
            'name' => $name,
            'grant_types' => ['authorization_code'],
            'redirect_uris' => $redirectUris,
        ]);
    }
}
