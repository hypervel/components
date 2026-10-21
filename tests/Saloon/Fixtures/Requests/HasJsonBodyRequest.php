<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Body\HasJsonBody;

class HasJsonBodyRequest extends Request
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
     * Define the default body.
     *
     * @return string[]
     */
    protected function defaultBody(): array
    {
        return [
            'name' => 'Sam',
            'catchphrase' => 'Yeehaw!',
        ];
    }
}
