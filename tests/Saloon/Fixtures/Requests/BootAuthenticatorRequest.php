<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Body\HasJsonBody;

class BootAuthenticatorRequest extends Request
{
    use HasJsonBody;

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
     * Configure a pending request for this resource.
     */
    public function boot(PendingRequest $pendingRequest): void
    {
        $pendingRequest->withToken('howdy-partner');
    }
}
