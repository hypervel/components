<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Saloon\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Saloon\Exceptions\Request\ServerException;
use Hypervel\Saloon\Exceptions\Request\Statuses\InternalServerErrorException;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\ErrorRequest;

// Upstream's live test API is replaced by the engine test server's /error route.
class RequestExceptionTest extends TestCase
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

    // Upstream's GuzzleSender case also expects Guzzle's ServerException as the previous exception. The HTTP client
    // does not throw for an error status, so there is none.
    public function testYouCanUseTheToExceptionMethodToGetTheDefaultRequestExceptionExceptionWithARealResponse(): void
    {
        $response = (new TestConnector($this->serverUrl()))->send(new ErrorRequest);

        $this->assertInstanceOf(Response::class, $response);

        $exception = $response->toException();

        $this->assertInstanceOf(InternalServerErrorException::class, $exception);
        $this->assertInstanceOf(ServerException::class, $exception);
        $this->assertSame(
            sprintf("HTTP request returned status code 500:\n%s\n", $response->body()),
            $exception->getMessage(),
        );
        $this->assertNull($exception->getPrevious());

        $this->expectExceptionObject($exception);

        $response->throw();
    }

    /**
     * Get the test server URL.
     */
    protected function serverUrl(): string
    {
        return sprintf('http://%s:%d', $this->getServerHost(), $this->getServerPort());
    }
}
