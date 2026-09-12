<?php

declare(strict_types=1);

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\BaseResource;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;

use function PHPStan\Testing\assertType;

class SaloonTypeUserData
{
}

/** @extends Request<SaloonTypeUserData> */
class SaloonTypeGetUserRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/user';
    }

    /** @param Response<SaloonTypeUserData> $response */
    public function createDtoFromResponse(Response $response): SaloonTypeUserData
    {
        return new SaloonTypeUserData;
    }
}

/** @extends Connector<SaloonTypeUserData> */
class SaloonTypeConnector extends Connector
{
    /**
     * Get an integration-specific value for resource type assertions.
     */
    public function integrationName(): string
    {
        return 'users';
    }

    public function resolveBaseUrl(): string
    {
        return 'https://example.com';
    }
}

/** @extends BaseResource<SaloonTypeConnector> */
class SaloonTypeUserResource extends BaseResource
{
    /**
     * Send a typed request through the resource connector.
     *
     * @return Response<SaloonTypeUserData>
     */
    public function get(): Response
    {
        assertType(SaloonTypeConnector::class, $this->connector);
        assertType('string', $this->connector->integrationName());

        return $this->connector->send(new SaloonTypeGetUserRequest);
    }
}

$resource = new SaloonTypeUserResource(new SaloonTypeConnector);
assertType('Hypervel\Saloon\Http\Response<SaloonTypeUserData>', $resource->get());
assertType(SaloonTypeUserData::class, $resource->get()->dto());

$response = (new SaloonTypeConnector)->send(new SaloonTypeGetUserRequest);

assertType('Hypervel\Saloon\Http\Response<SaloonTypeUserData>', $response);
assertType(SaloonTypeUserData::class, $response->dto());
assertType(SaloonTypeUserData::class, $response->dtoOrFail());
assertType('Hypervel\Saloon\Http\Request<SaloonTypeUserData>', $response->request());
