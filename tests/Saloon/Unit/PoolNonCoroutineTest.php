<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class PoolNonCoroutineTest extends TestCase
{
    use VerifiesDoubles;

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
        $config = new ConfigRepository(['saloon' => ['connection' => ['name' => 'saloon']]]);

        return new SaloonManager(
            new Sender($http, $config),
            Double::for(CacheFactory::class)->strict(),
            Double::for(RateLimiter::class, override: true)->strict()->instance(),
            $config,
            new Dispatcher,
        );
    }
}

class PoolNonCoroutineConnectorStub extends Connector
{
    /**
     * Create a connector that sends through the given manager.
     */
    public function __construct(protected SaloonManager $manager)
    {
    }

    /**
     * Resolve the integration base URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }

    /**
     * Send a request through the isolated manager.
     */
    public function send(Request $request, ?MockClient $mockClient = null): Response
    {
        return $this->manager->send($this, $request, $mockClient);
    }
}

class PoolNonCoroutineRequestStub extends Request
{
    protected Method $method = Method::GET;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/users';
    }
}
