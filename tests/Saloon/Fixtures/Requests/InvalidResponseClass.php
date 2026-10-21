<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Tests\Saloon\Fixtures\Responses\UserResponseNoExtendSaloonResponse;

class InvalidResponseClass extends Request
{
    /**
     * Define the method that the request will use.
     */
    protected Method $method = Method::GET;

    /**
     * Define the custom response.
     */
    protected ?string $response = UserResponseNoExtendSaloonResponse::class;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/user';
    }
}
