<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Tests\Saloon\Fixtures\Data\UserWithResponse;

class DTOWithResponseRequest extends Request
{
    /**
     * Define the method that the request will use.
     */
    protected Method $method = Method::GET;

    // Upstream's $connector property is removed: the request does not use HasConnector, so nothing reads it.

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/user';
    }

    /**
     * Create a new request instance.
     */
    public function __construct(public ?int $userId = null, public ?int $groupId = null)
    {
    }

    /**
     * Cast to a User.
     */
    public function createDtoFromResponse(Response $response): object
    {
        return UserWithResponse::fromResponse($response);
    }
}
