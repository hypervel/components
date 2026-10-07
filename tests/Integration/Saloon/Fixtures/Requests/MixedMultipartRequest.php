<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Body\HasMultipartBody;

class MixedMultipartRequest extends Request
{
    use HasMultipartBody;

    /**
     * Define the method that the request will use.
     */
    protected Method $method = Method::POST;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/mixed-multipart';
    }
}
