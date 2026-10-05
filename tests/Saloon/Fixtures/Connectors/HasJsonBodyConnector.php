<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Traits\Body\HasJsonBody;
use Hypervel\Saloon\Traits\Plugins\AcceptsJson;

class HasJsonBodyConnector extends Connector
{
    use AcceptsJson;
    use HasJsonBody;

    /**
     * Create a new connector instance.
     */
    public function __construct(protected ?string $url = null)
    {
    }

    /**
     * Define the base url of the api.
     */
    public function resolveBaseUrl(): string
    {
        return $this->url ?? TestConnector::API_URL;
    }

    /**
     * Define the base headers that will be applied in every request.
     *
     * @return string[]
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
        ];
    }

    /**
     * Define the default body.
     *
     * @return string[]
     */
    protected function defaultBody(): array
    {
        return [
            'name' => 'Gareth',
            'drink' => 'Moonshine',
        ];
    }
}
