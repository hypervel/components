<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Saloon\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\ErrorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

// Upstream's live test API is replaced by the engine test server's /user and /error routes.
class PoolTest extends TestCase
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

    public function testYouCanCreateAPoolOnAConnector(): void
    {
        $connector = new TestConnector($this->serverUrl());
        $handled = [];
        $exceptions = [];

        $pool = $connector->pool([
            new UserRequest,
            new UserRequest,
            new UserRequest,
            new UserRequest,
            new UserRequest,
            new ErrorRequest,
        ]);

        $pool->setConcurrency(6);

        $pool->withResponseHandler(function (Response $response, int $key) use (&$handled): void {
            $handled[$key] = $response;
        });

        $pool->withExceptionHandler(function (RequestException $exception, int $key) use (&$exceptions): void {
            $exceptions[$key] = $exception;
        });

        $responses = $pool->send();

        // Responses follow input order whatever order the requests complete in.
        $this->assertSame([0, 1, 2, 3, 4], array_keys($responses));

        ksort($handled);

        $this->assertSame($responses, $handled);

        foreach ($responses as $response) {
            $this->assertFalse($response->isMocked());
            $this->assertSame(200, $response->status());
            $this->assertSame([
                'name' => 'Sammyjo20',
                'actual_name' => 'Sam',
                'twitter' => '@carre_sam',
            ], $response->json());
        }

        $this->assertSame([5], array_keys($exceptions));
        $this->assertSame(500, $exceptions[5]->response()->status());
        $this->assertFalse($exceptions[5]->response()->isMocked());
    }

    /**
     * Get the test server URL.
     */
    protected function serverUrl(): string
    {
        return sprintf('http://%s:%d', $this->getServerHost(), $this->getServerPort());
    }
}
