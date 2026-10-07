<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Tests\Saloon\Fixtures\Plugins\WithBootTestPlugin;

class UserRequestWithBootPlugin extends Request
{
    use WithBootTestPlugin;

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
     * Create a new request instance.
     */
    public function __construct(public ?int $userId = null, public ?int $groupId = null)
    {
    }
}
