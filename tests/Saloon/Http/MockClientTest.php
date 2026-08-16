<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Events\Dispatcher;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Client\Factory;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Exceptions\NoMockResponseFoundException;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\Fixture;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;

class MockClientTest extends TestCase
{
    protected Filesystem $files;

    protected string $fixturePath;

    public function testRequestConnectorUrlAndSequenceResponsesUseTheirPrecedence(): void
    {
        $http = $this->http();
        $client = new MockClient([
            new MockResponse('sequence'),
            MockConnectorStub::class => new MockResponse('connector'),
            'https://api.example.com/users/*' => new MockResponse('url'),
            MockRequestA::class => new MockResponse('request'),
        ]);
        $manager = $this->manager($http);

        $request = $manager->send(new MockConnectorStub, new MockRequestA, $client);
        $connector = $manager->send(new MockConnectorStub, new MockRequestB, $client);
        $url = $manager->send(new OtherMockConnectorStub, new MockRequestB, $client);
        $sequence = $manager->send(new OtherMockConnectorStub, new MockRequestC, $client);

        $this->assertSame('request', $request->body());
        $this->assertSame('connector', $connector->body());
        $this->assertSame('url', $url->body());
        $this->assertSame('sequence', $sequence->body());
        $this->assertTrue($request->isMocked());
        $this->assertFalse($client->isEmpty());
        $client->assertSentCount(4);
        $http->assertNothingSent();
    }

    public function testUnmatchedRequestsAreStrictUnlessTheirUrlIsAllowed(): void
    {
        $http = $this->http();
        $http->fake(['*' => Factory::response('network')]);
        $manager = $this->manager($http);
        $client = (new MockClient)->allowStrayRequests(['https://api.example.com/allowed*']);

        $allowed = $manager->send(new OtherMockConnectorStub, new AllowedMockRequest, $client);

        $this->assertSame('network', $allowed->body());
        $this->assertFalse($allowed->isMocked());
        $client->assertSentCount(1);

        $this->expectException(NoMockResponseFoundException::class);

        $manager->send(new OtherMockConnectorStub, new MockRequestC, $client);
    }

    public function testExplicitRequestAndGlobalMockClientsUseTheExpectedPrecedence(): void
    {
        $http = $this->http();
        $manager = $this->manager($http);
        $manager->fake([new MockResponse('global')]);
        $requestClient = new MockClient([new MockResponse('request')]);
        $explicitClient = new MockClient([new MockResponse('explicit')]);
        $request = (new MockRequestA)->withMockClient($requestClient);

        $this->assertSame('explicit', $manager->send(new MockConnectorStub, $request, $explicitClient)->body());
        $this->assertSame('request', $manager->send(new MockConnectorStub, $request)->body());
        $this->assertSame('global', $manager->send(new MockConnectorStub, new MockRequestA)->body());
    }

    public function testAssertionsSupportRequestClassesUrlsAndTypedClosureUnions(): void
    {
        $http = $this->http();
        $manager = $this->manager($http);
        $client = new MockClient([
            new MockResponse('c'),
            new MockResponse('a'),
            new MockResponse('b'),
        ]);
        $manager->send(new OtherMockConnectorStub, new MockRequestC, $client);
        $manager->send(new MockConnectorStub, new MockRequestA, $client);
        $manager->send(new MockConnectorStub, new MockRequestB, $client);
        $typedCalls = 0;

        $client->assertSent(function (MockRequestA|MockRequestB $request) use (&$typedCalls): bool {
            ++$typedCalls;

            return $request instanceof MockRequestA;
        });
        $client->assertSent(MockRequestA::class);
        $client->assertSent('https://api.example.com/users/*');
        $client->assertNotSent('https://api.example.com/missing');
        $client->assertSentInOrder([
            MockRequestC::class,
            MockRequestA::class,
            MockRequestB::class,
        ]);
        $client->assertSentCount(1, MockRequestA::class);

        $this->assertSame(1, $typedCalls);
        $this->assertSame(MockRequestB::class, $client->lastRequest()::class);
        $this->assertSame(MockRequestB::class, $client->lastPendingRequest()?->request()::class);
        $this->assertCount(2, $client->recorded(fn (Request $request): bool => $request instanceof MockRequestA || $request instanceof MockRequestB));
    }

    public function testMissingFixtureRecordsTheNetworkResponseAndThenReplaysIt(): void
    {
        $http = $this->http();
        $http->fake(['*' => Factory::response('recorded', 201, ['X-Trace' => 'abc'])]);
        $manager = $this->manager($http);
        $client = new MockClient([
            MockRequestA::class => new Fixture('users/show', $this->files),
        ]);

        $recorded = $manager->send(new MockConnectorStub, new MockRequestA, $client);
        $replayed = $manager->send(new MockConnectorStub, new MockRequestA, $client);

        $this->assertFalse($recorded->isMocked());
        $this->assertTrue($replayed->isMocked());
        $this->assertSame('recorded', $replayed->body());
        $this->assertSame('abc', $replayed->header('X-Trace'));
        $http->assertSentCount(1);
        $client->assertSentCount(2);
    }

    public function testGlobalClientDelegatesToTheContainerManagerWithoutStaticState(): void
    {
        $manager = $this->manager($this->http());
        $container = new Container;
        $container->instance('saloon', $manager);
        Container::setInstance($container);

        $global = MockClient::global([new MockResponse('global')]);

        $this->assertSame($global, MockClient::getGlobal());
        $this->assertSame($global, $manager->mockClient());

        MockClient::destroyGlobal();

        $this->assertNull(MockClient::getGlobal());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->fixturePath = ParallelTesting::tempDir('SaloonMockClientTest');
        $this->files->deleteDirectory($this->fixturePath);
        $this->files->ensureDirectoryExists($this->fixturePath);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    /**
     * Create an isolated HTTP factory with the Saloon connection.
     */
    protected function http(): Factory
    {
        $http = new Factory;
        $http->registerConnection('saloon');

        return $http;
    }

    /**
     * Create the manager with isolated framework services.
     */
    protected function manager(Factory $http): SaloonManager
    {
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')
            ->with('saloon.connection.name')
            ->andReturn('saloon');
        $config->shouldReceive('string')
            ->with('saloon.fixtures.path', m::type(Closure::class))
            ->andReturn($this->fixturePath);
        $config->shouldReceive('boolean')
            ->with('saloon.fixtures.throw_on_missing', false)
            ->andReturn(false);

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

        return $manager;
    }
}

class MockConnectorStub extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }
}

class OtherMockConnectorStub extends MockConnectorStub
{
}

class MockRequestA extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/users/a';
    }
}

class MockRequestB extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/users/b';
    }
}

class MockRequestC extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/other';
    }
}

class AllowedMockRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/allowed/request';
    }
}
