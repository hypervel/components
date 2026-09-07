<?php

declare(strict_types=1);

use Hypervel\Saloon\Enums\Method;
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
    public function resolveBaseUrl(): string
    {
        return 'https://example.com';
    }
}

$response = (new SaloonTypeConnector)->send(new SaloonTypeGetUserRequest);

assertType('Hypervel\Saloon\Http\Response<SaloonTypeUserData>', $response);
assertType(SaloonTypeUserData::class, $response->dto());
assertType(SaloonTypeUserData::class, $response->dtoOrFail());
assertType('Hypervel\Saloon\Http\Request<SaloonTypeUserData>', $response->request());
