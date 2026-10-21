<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Saloon\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\RetryConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\HeaderErrorRequest;

// Upstream's live test API is replaced by the engine test server's /header-error route.
class RetryConnectorTest extends TestCase
{
    use InteractsWithServer;

    protected int $serverPort = 19505;

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Set up the test server connection.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInteractsWithServer();
    }

    public function testRetryAgainstALiveEndpointToTestTheTransport(): void
    {
        $requestCount = 0;
        $index = 0;
        $connector = new RetryConnector(6, when: function (
            RequestException $exception,
            PendingRequest $pendingRequest,
        ) use (&$index): bool {
            $pendingRequest->request()->replaceHeaders(['X-Yee-Haw' => (string) $index++]);

            return true;
        }, url: $this->serverUrl());
        $request = new HeaderErrorRequest;
        $request->middleware()->onRequest(function () use (&$requestCount): void {
            ++$requestCount;
        });

        $response = $connector->send($request);

        // Request count is five because:
        // Request 1 - no header
        // Request 2 - header but 0
        // Request 3 - header but 1
        // Request 4 - header but 2
        // Request 5 - header but 3
        $this->assertSame(5, $requestCount);
        $this->assertSame('Success!', $response->body());
    }

    /**
     * Get the test server URL.
     */
    protected function serverUrl(): string
    {
        return sprintf('http://%s:%d', $this->getServerHost(), $this->getServerPort());
    }
}
