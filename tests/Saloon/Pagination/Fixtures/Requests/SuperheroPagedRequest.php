<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Pagination\Contracts\Paginatable;

class SuperheroPagedRequest extends Request implements Paginatable
{
    protected Method $method = Method::GET;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/superheroes/per-page';
    }
}
