<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Saloon;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;

class SaloonClientTest extends TestCase
{
    use InteractsWithServer;

    protected int $serverPort = 19505;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInteractsWithServer();
    }

    public function testConnectorSendsThroughTheRegisteredFrameworkConnection(): void
    {
        $connector = new SaloonEngineConnector($this->serverUrl());
        $response = $connector->send(new SaloonEngineRequest);

        $this->assertSame(200, $response->status());
        $this->assertSame('Hello World.', $response->body());
        $this->assertSame(rtrim($this->serverUrl(), '/'), (string) $response->toPsrRequest()->getUri());
        $this->assertFalse($response->isMocked());
        $this->assertFalse($response->isCached());
        $this->assertNotEmpty($response->handlerStats());
    }

    public function testSequentialRequestsReuseTheNamedConnection(): void
    {
        $connector = new SaloonEngineConnector($this->serverUrl());
        $connectionTimes = array_map(
            fn (): int => $connector->send(new SaloonEngineRequest)->handlerStats()['connect_time_us'],
            range(1, 4),
        );

        $this->assertGreaterThan(0, $connectionTimes[0]);
        $this->assertSame([0, 0, 0], array_slice($connectionTimes, 1));
    }

    public function testPoolSendsConcurrentRequestsThroughTheSameConnector(): void
    {
        $connector = new SaloonEngineConnector($this->serverUrl());
        $responses = $connector->pool(
            array_map(fn (): SaloonEngineRequest => new SaloonEngineRequest, range(1, 6)),
            concurrency: 3,
        )->send();

        $this->assertSame(
            array_fill(0, 6, 'Hello World.'),
            array_map(fn (Response $response): string => $response->body(), $responses),
        );
    }

    /**
     * Get the test server URL.
     */
    protected function serverUrl(): string
    {
        return sprintf('http://%s:%d/', $this->getServerHost(), $this->getServerPort());
    }
}

class SaloonEngineConnector extends Connector
{
    /**
     * Create a test connector.
     */
    public function __construct(protected readonly string $baseUrl)
    {
    }

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }
}

class SaloonEngineRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '';
    }
}
