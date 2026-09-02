<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Saloon\SaloonIntegrationTest;

use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Http\Client\Response as HttpResponse;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Contracts\DataObjects\WithResponse;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Traits\Responses\HasResponse;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class SaloonIntegrationTest extends TestCase
{
    /**
     * Get package providers for the Saloon integration test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testRequestDataTakesPriorityAndReceivesItsResponse(): void
    {
        $response = $this->response(
            connector: new DataConnector,
            request: new DataRequest,
        );
        $data = $response->dto();

        $this->assertInstanceOf(SaloonUserData::class, $data);
        $this->assertSame(7, $data->id);
        $this->assertSame('Taylor', $data->name);
        $this->assertSame('request', $data->source);
        $this->assertSame($response, $data->getResponse());
    }

    public function testConnectorDataIsUsedWhenTheRequestReturnsNothing(): void
    {
        $response = $this->response(
            connector: new DataConnector,
            request: new PlainDataRequest,
        );
        $data = $response->dto();

        $this->assertInstanceOf(SaloonUserData::class, $data);
        $this->assertSame('connector', $data->source);
        $this->assertSame($response, $data->getResponse());
    }

    /**
     * Create a Saloon response for a data operation.
     */
    protected function response(Connector $connector, Request $request): Response
    {
        $pendingRequest = new PendingRequest(
            $connector,
            $request,
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
        );
        $psrRequest = new PsrRequest($request->method()->value, 'https://api.example.com/users/7');

        return Response::fromResponse(
            new HttpResponse(new PsrResponse(
                200,
                ['Content-Type' => 'application/json'],
                '{"id":"7","name":"Taylor"}',
            )),
            $pendingRequest,
            $psrRequest,
        );
    }
}

/** @extends Connector<SaloonUserData> */
class DataConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }

    public function createDtoFromResponse(Response $response): SaloonUserData
    {
        return SaloonUserData::from([
            ...$response->json(),
            'source' => 'connector',
        ]);
    }
}

/** @extends Request<SaloonUserData> */
class DataRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/users/7';
    }

    public function createDtoFromResponse(Response $response): SaloonUserData
    {
        return SaloonUserData::from([
            ...$response->json(),
            'source' => 'request',
        ]);
    }
}

/** @extends Request<SaloonUserData> */
class PlainDataRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/users/7';
    }
}

class SaloonUserData extends Data implements WithResponse
{
    use HasResponse;

    public function __construct(
        public int $id,
        public string $name,
        public string $source,
    ) {
    }
}
