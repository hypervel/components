<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Saloon\Unit;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

// Upstream's live test API is replaced by the engine test server's /user route.
class ResponseTest extends TestCase
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

    public function testYouCanGetTheResponseStreamAsARawResource(): void
    {
        $response = (new TestConnector($this->serverUrl()))->send(new UserRequest);

        $resource = $response->getRawStream();

        $this->assertIsResource($resource);

        $this->assertSame('{"name":"Sammyjo20","actual_name":"Sam","twitter":"@carre_sam"}', stream_get_contents($resource));
    }

    /**
     * Get the test server URL.
     */
    protected function serverUrl(): string
    {
        return sprintf('http://%s:%d', $this->getServerHost(), $this->getServerPort());
    }
}
