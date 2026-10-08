<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Traits\Plugins\AcceptsJson;

class QueryParameterConnector extends Connector
{
    use AcceptsJson;

    /**
     * Create a new connector instance.
     */
    public function __construct(public ?string $url = null)
    {
        if (is_null($this->url)) {
            $this->url = TestConnector::API_URL;
        }
    }

    /**
     * Define the base url of the api.
     */
    public function resolveBaseUrl(): string
    {
        return $this->url;
    }

    /**
     * Define the default query parameters.
     */
    protected function defaultQuery(): array
    {
        return [
            'sort' => 'first_name',
        ];
    }
}
