<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Data\MultipartValue;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Traits\Body\HasMultipartBody;
use Hypervel\Saloon\Traits\Plugins\AcceptsJson;

class HasMultipartBodyConnector extends Connector
{
    use AcceptsJson;
    use HasMultipartBody;

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
     * @return list<MultipartValue>
     */
    protected function defaultBody(): array
    {
        return [
            new MultipartValue('nickname', 'Gareth', 'user.txt', ['X-Saloon' => 'Yee-haw!']),
            new MultipartValue('drink', 'Moonshine', 'moonshine.txt', ['X-My-Head' => 'Spinning!']),
        ];
    }
}
