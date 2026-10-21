<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Saloon\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Integration\Saloon\Fixtures\Requests\SoloErrorRequest;
use Hypervel\Tests\Integration\Saloon\Fixtures\Requests\SoloUserRequest;

// The fake case for standalone requests is in tests/Saloon/Feature/SoloRequestTest.php.
class SoloRequestTest extends TestCase
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

    public function testASoloRequestCanBeSentSynchronously(): void
    {
        $request = new SoloUserRequest($this->serverUrl());
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

    public function testASynchronousSoloRequestCanHandleAnExceptionProperly(): void
    {
        $request = new SoloErrorRequest($this->serverUrl());
        $response = $request->send();

        $this->assertFalse($response->isMocked());
        $this->assertSame(500, $response->status());
    }

    // REMOVED: "a solo request can be sent asynchronously" and "a asynchronous solo request can handle an exception
    // property". Coroutine pools replace promises and sendAsync().

    /**
     * Get the test server URL.
     */
    protected function serverUrl(): string
    {
        return sprintf('http://%s:%d', $this->getServerHost(), $this->getServerPort());
    }
}
