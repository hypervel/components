<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Contracts\Auth\Authenticatable;
use stdClass;

class PassportClientRepository
{
    /**
     * Create a client without persistence for registration tests.
     */
    public function createAuthorizationCodeGrantClient(string $name, array $redirectUris, bool $confidential = true, ?Authenticatable $user = null, bool $enableDeviceFlow = false): stdClass
    {
        return (object) [
            'id' => 'test-client-id',
            'grant_types' => ['authorization_code'],
            'redirect_uris' => $redirectUris,
        ];
    }
}
