<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\Factory;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Connectors\NullConnector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\Http\SoloRequest;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Tests\TestCase;
use Mockery as m;

class SoloRequestTest extends TestCase
{
    public function testItSendsAnAbsoluteEndpointThroughTheNormalLifecycle(): void
    {
        $request = new SoloRequestStub;
        $mockClient = new MockClient([new MockResponse('complete', 201)]);

        $response = $request->send($mockClient);

        $this->assertInstanceOf(NullConnector::class, $request->connector());
        $this->assertSame($request->connector(), $request->connector());
        $this->assertSame('https://api.example.com/users', (string) $response->pendingRequest()->uri());
        $this->assertSame(201, $response->status());
        $this->assertSame('complete', $response->body());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $http = new Factory;
        $http->registerConnection('saloon');
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')
            ->with('saloon.connection.name')
            ->andReturn('saloon');
        $manager = new SaloonManager(
            new Sender($http, $config),
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
            $config,
            new Dispatcher,
        );
        $container = new Container;
        $container->instance('saloon', $manager);
        Container::setInstance($container);
    }
}

class SoloRequestStub extends SoloRequest
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'https://api.example.com/users';
    }
}
