<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Auth\RequiresAuth;
use Hypervel\Tests\Saloon\Fixtures\Authenticators\PizzaAuthenticator;

class DefaultPizzaAuthenticatorRequest extends Request
{
    use RequiresAuth;

    /**
     * Define the method that the request will use.
     */
    protected Method $method = Method::GET;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/user';
    }

    /**
     * Provide default authentication.
     */
    protected function defaultAuth(): ?Authenticator
    {
        return new PizzaAuthenticator('BBQ Chicken', 'Lemonade');
    }

    /**
     * Create a new request instance.
     */
    public function __construct(public ?int $userId = null, public ?int $groupId = null)
    {
    }
}
