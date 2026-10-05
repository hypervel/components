<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Saloon\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\ErrorRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\HasConnectorUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

// Upstream's live test API is replaced by the engine test server's /user and /error routes.
// laravel-plugin's Feature/RequestTest repeats the first two cases, so they cover it too.
class RequestTest extends TestCase
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

    public function testARequestCanBeMadeSuccessfully(): void
    {
        $connector = new TestConnector($this->serverUrl());
        $response = $connector->send(new UserRequest);

        $data = $response->json();

        // Upstream also asserts that the pending request is not asynchronous; coroutine pools replace sendAsync().
        $this->assertInstanceOf(Response::class, $response);
        $this->assertFalse($response->isMocked());
        $this->assertSame(200, $response->status());

        $this->assertSame([
            'name' => 'Sammyjo20',
            'actual_name' => 'Sam',
            'twitter' => '@carre_sam',
        ], $data);
    }

    public function testARequestCanHandleAnExceptionProperly(): void
    {
        $connector = new TestConnector($this->serverUrl());
        $response = $connector->send(new ErrorRequest);

        $this->assertFalse($response->isMocked());
        $this->assertSame(500, $response->status());
    }

    public function testARequestWithHasConnectorCanBeSentIndividually(): void
    {
        $request = new HasConnectorUserRequest;

        $this->assertInstanceOf(TestConnector::class, $request->connector());

        // The engine test server replaces the connector's default URL. Upstream also asserts the connector's
        // sender; Hypervel connectors send through the framework HTTP client instead.
        $request->setConnector(new TestConnector($this->serverUrl()));

        $this->assertInstanceOf(PendingRequest::class, $request->createPendingRequest());

        $response = $request->send();

        $data = $response->json();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertFalse($response->isMocked());
        $this->assertSame(200, $response->status());

        $this->assertSame([
            'name' => 'Sammyjo20',
            'actual_name' => 'Sam',
            'twitter' => '@carre_sam',
        ], $data);
    }

    /**
     * Get the test server URL.
     */
    protected function serverUrl(): string
    {
        return sprintf('http://%s:%d', $this->getServerHost(), $this->getServerPort());
    }
}
