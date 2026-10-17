<?php

declare(strict_types=1);

namespace Hypervel\Passport\Events;

use Hypervel\Http\Request;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;

class AuthorizationRequestValidated
{
    /** @var list<string> */
    public array $prompts = [];

    /**
     * Create a synchronous authorization-policy event.
     *
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public readonly Request $request,
        public readonly array $parameters,
        public readonly AuthorizationRequestInterface $authorizationRequest,
    ) {
    }
}
