<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\Factory;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Tests\TestCase;
use Mockery as m;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    public function testSharedConnectorKeepsConcurrentOperationStateIsolated(): void
    {
        $http = new Factory;
        $http->registerConnection('saloon');
        $http->preventStrayRequests();
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')->with('saloon.connection.name')->andReturn('saloon');
        $manager = new SaloonManager(
            new Sender($http, $config),
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
            $config,
            new Dispatcher,
        );
        $connector = new CoroutineIsolationConnectorStub;

        [$tenantA, $tenantB] = parallel([
            fn (): array => $this->sendIsolatedOperation($manager, $connector, 'tenant-a'),
            fn (): array => $this->sendIsolatedOperation($manager, $connector, 'tenant-b'),
        ]);

        $this->assertSame([
            'mock' => 'tenant-a',
            'header' => 'tenant-a',
            'authorization' => 'Bearer tenant-a-token',
            'middleware' => 'tenant-a',
            'cookie' => 'tenant-a-session',
        ], $tenantA);
        $this->assertSame([
            'mock' => 'tenant-b',
            'header' => 'tenant-b',
            'authorization' => 'Bearer tenant-b-token',
            'middleware' => 'tenant-b',
            'cookie' => 'tenant-b-session',
        ], $tenantB);
        $http->assertNothingSent();
    }

    /**
     * Send one deliberately interleaved operation.
     *
     * @return array<string, string>
     */
    protected function sendIsolatedOperation(
        SaloonManager $manager,
        Connector $connector,
        string $tenant,
    ): array {
        $mockClient = new MockClient([
            static function (PendingRequest $pendingRequest) use ($tenant): MockResponse {
                $cookieGroup = $pendingRequest->cookies()[0];

                return MockResponse::make([
                    'mock' => $tenant,
                    'header' => $pendingRequest->headers()['X-Tenant'],
                    'authorization' => $pendingRequest->headers()['Authorization'],
                    'middleware' => $pendingRequest->headers()['X-Middleware'],
                    'cookie' => $cookieGroup['cookies']['session'],
                ]);
            },
        ]);
        $request = (new CoroutineIsolationRequestStub)
            ->withHeader('X-Tenant', $tenant)
            ->withToken($tenant . '-token')
            ->withCookies(['session' => $tenant . '-session'], '.example.com')
            ->withMockClient($mockClient);
        $request->middleware()->onRequest(function (PendingRequest $pendingRequest) use ($tenant): void {
            $pendingRequest->withHeader('X-Middleware', $tenant);
            usleep(5000);
        });

        /** @var array<string, string> */
        return $manager->send($connector, $request)->json();
    }
}

class CoroutineIsolationConnectorStub extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }
}

class CoroutineIsolationRequestStub extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/users';
    }
}
