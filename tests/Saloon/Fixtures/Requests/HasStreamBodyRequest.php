<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Body\HasStreamBody;

class HasStreamBodyRequest extends Request
{
    use HasStreamBody;

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
     * @return resource
     */
    protected function defaultBody(): mixed
    {
        $temp = fopen('php://memory', 'rw');

        fwrite($temp, 'Howdy, Partner');

        rewind($temp);

        return $temp;
    }
}
