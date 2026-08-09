<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\PackageManifest;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Sentry\Transport\Pool;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionProperty;
use Sentry\Event;
use Sentry\HttpClient\HttpClient;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\HttpClient\Response;
use Sentry\Options;
use Sentry\Transport\ResultStatus;

class PoolTest extends TestCase
{
    public function testCreatesTheSdkHttpClient(): void
    {
        $manifest = m::mock(PackageManifest::class);
        $manifest->shouldReceive('version')
            ->once()
            ->with('hypervel/sentry')
            ->andReturn('1.2.3');

        Container::getInstance()->singleton(PackageManifest::class, fn () => $manifest);

        $pool = new InspectableSentryTransportPool(
            new Options,
            $this->poolOptions(),
        );

        $httpClient = $pool->createHttpClient();

        $this->assertInstanceOf(HttpClient::class, $httpClient);
        $this->assertSame('sentry.php.hypervel', (new ReflectionProperty($httpClient, 'sdkIdentifier'))->getValue($httpClient));
        $this->assertSame('1.2.3', (new ReflectionProperty($httpClient, 'sdkVersion'))->getValue($httpClient));
        $pool->close();
    }

    public function testCreatesTheSdkHttpClientWithTheFrameworkVersionAsFallback(): void
    {
        $manifest = m::mock(PackageManifest::class);
        $manifest->shouldReceive('version')
            ->once()
            ->with('hypervel/sentry')
            ->andReturn(null);

        Container::getInstance()->singleton(PackageManifest::class, fn () => $manifest);

        $pool = new InspectableSentryTransportPool(new Options, $this->poolOptions());
        $httpClient = $pool->createHttpClient();

        $this->assertSame(Application::VERSION, (new ReflectionProperty($httpClient, 'sdkVersion'))->getValue($httpClient));
        $pool->close();
    }

    public function testPooledTransportRetainsRateLimitsFromRealResponses(): void
    {
        $httpClient = m::mock(HttpClientInterface::class);
        $httpClient->shouldReceive('sendRequest')
            ->once()
            ->andReturn(new Response(429, [
                'X-Sentry-Rate-Limits' => ['60:error'],
            ], ''));

        $pool = new ScriptedSentryTransportPool(
            new Options(['dsn' => 'https://public@example.com/1']),
            $this->poolOptions(),
            $httpClient,
        );

        $transport = $pool->get();
        $this->assertSame(ResultStatus::rateLimit(), $transport->send(Event::createEvent())->getStatus());
        $pool->release($transport);

        $sameTransport = $pool->get();
        $this->assertSame($transport, $sameTransport);
        $this->assertSame(ResultStatus::rateLimit(), $sameTransport->send(Event::createEvent())->getStatus());
        $pool->release($sameTransport);
        $pool->close();
    }

    /**
     * Get pool options for one reusable transport.
     */
    private function poolOptions(): PoolOptions
    {
        return PoolOptions::fromArray([
            'min_retained_objects' => 0,
            'max_objects' => 1,
            'wait_timeout' => 0.1,
            'max_lifetime' => 0,
            'idle_ttl' => null,
        ]);
    }
}

class InspectableSentryTransportPool extends Pool
{
    public function createHttpClient(): HttpClientInterface
    {
        return $this->getHttpClient();
    }
}

class ScriptedSentryTransportPool extends Pool
{
    public function __construct(
        Options $sentryOptions,
        PoolOptions $poolOptions,
        private HttpClientInterface $httpClient,
    ) {
        parent::__construct($sentryOptions, $poolOptions);
    }

    protected function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient;
    }
}
