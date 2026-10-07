<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;

class HeaderErrorRequest extends Request
{
    /**
     * Define the method that the request will use.
     */
    protected Method $method = Method::GET;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/header-error';
    }
}
