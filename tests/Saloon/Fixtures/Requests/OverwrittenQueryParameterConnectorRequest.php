<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Tests\Saloon\Fixtures\Connectors\QueryParameterConnector;

class OverwrittenQueryParameterConnectorRequest extends Request
{
    /**
     * Define the method that the request will use.
     */
    protected Method $method = Method::GET;

    /**
     * The connector.
     */
    protected string $connector = QueryParameterConnector::class;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/user';
    }

    /**
     * Define the default query parameters.
     */
    protected function defaultQuery(): array
    {
        return [
            'sort' => 'date_of_birth',
        ];
    }
}
