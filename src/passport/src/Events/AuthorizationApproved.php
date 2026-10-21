<?php

declare(strict_types=1);

namespace Hypervel\Passport\Events;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Http\Request;
use Hypervel\Passport\Client;
use Hypervel\Passport\Enums\AuthorizationApproval;

class AuthorizationApproved
{
    /**
     * Create a synchronous event inside the authorization's credential transaction.
     *
     * @param array<string, mixed> $parameters
     * @param list<string> $scopes
     * @param array<string, array> $permissions
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public readonly Request $request,
        public readonly array $parameters,
        public readonly Authenticatable $user,
        public readonly ?string $provider,
        public readonly string $guard,
        public readonly Client $client,
        public readonly string $grantType,
        public readonly AuthorizationApproval $approval,
        public readonly array $scopes,
        public readonly array $permissions,
        public array $attributes,
    ) {
    }
}
