<?php

declare(strict_types=1);

namespace Hypervel\Passport\Events;

use DateInterval;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Http\Request;
use Hypervel\Passport\Client;
use Hypervel\Passport\Passport;
use Hypervel\Passport\Scope;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;

class AuthorizationUserResolved
{
    /**
     * Values are kept in the pending browser authorization and must be JSON-compatible.
     *
     * @var array<string, mixed>
     */
    public array $attributes = [];

    /**
     * Permission sets are kept in the pending browser authorization and must be JSON-compatible.
     *
     * @var array<string, list<string>>
     */
    public array $permissions = [];

    public ?DateInterval $rememberConsentFor;

    /**
     * Create a synchronous policy event for the approving identity.
     *
     * @param array<string, mixed> $parameters
     * @param list<Scope> $scopes
     */
    public function __construct(
        public readonly Request $request,
        public readonly array $parameters,
        public readonly Authenticatable $user,
        public readonly ?string $provider,
        public readonly string $guard,
        public readonly Client $client,
        public readonly array $scopes,
        public readonly AuthorizationRequestInterface|DeviceCodeEntityInterface $authorizationRequest,
    ) {
        $duration = Passport::rememberConsentFor();
        $this->rememberConsentFor = $duration === null ? null : clone $duration;
    }
}
