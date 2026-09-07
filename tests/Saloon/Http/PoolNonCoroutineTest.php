<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\Factory;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Tests\TestCase;
use Mockery as m;

class PoolNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testPoolEntersOneRootCoroutineWhenCalledFromCliCode(): void
    {
        $manager = $this->manager();
        $manager->fake([PoolNonCoroutineRequestStub::class => MockResponse::make(['ok' => true])]);
        $connector = new PoolNonCoroutineConnectorStub($manager);

        $responses = $connector->pool([
            'one' => new PoolNonCoroutineRequestStub,
            'two' => new PoolNonCoroutineRequestStub,
        ], concurrency: 2)->send();

        $this->assertSame(['one', 'two'], array_keys($responses));
        $this->assertSame(['ok' => true], $responses['one']->json());
        $this->assertSame(['ok' => true], $responses['two']->json());
    }

    /**
     * Create the manager with isolated framework services.
     */
    protected function manager(): SaloonManager
    {
        $http = new Factory;
        $http->registerConnection('saloon');
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')
            ->with('saloon.connection.name')
            ->andReturn('saloon');

        return new SaloonManager(
            new Sender($http, $config),
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
            $config,
            new Dispatcher,
        );
    }
}

class PoolNonCoroutineConnectorStub extends Connector
{
    public function __construct(protected SaloonManager $manager)
    {
    }

    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }

    public function send(Request $request, ?MockClient $mockClient = null): Response
    {
        return $this->manager->send($this, $request, $mockClient);
    }
}

class PoolNonCoroutineRequestStub extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/users';
    }
}
