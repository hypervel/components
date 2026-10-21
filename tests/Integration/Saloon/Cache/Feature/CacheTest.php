<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Saloon\Cache\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Cache\Fixtures\Requests\CachedUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;

// Upstream's live test API is replaced by the engine test server's /user route.
class CacheTest extends TestCase
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

    public function testARequestWithTheHasCachingTraitWillCacheTheResponseWithARealRequest(): void
    {
        $responseA = TestConnector::make($this->serverUrl())->send(new CachedUserRequest);

        $responseBody = [
            'name' => 'Sammyjo20',
            'actual_name' => 'Sam',
            'twitter' => '@carre_sam',
        ];

        $this->assertFalse($responseA->isFaked());
        $this->assertFalse($responseA->isCached());
        $this->assertSame($responseBody, $responseA->json());

        // Now send a response without the mock middleware, and it should be cached!

        $responseB = TestConnector::make($this->serverUrl())->send(new CachedUserRequest);

        $this->assertTrue($responseB->isFaked());
        $this->assertTrue($responseB->isCached());
        $this->assertSame($responseBody, $responseB->json());
    }

    /**
     * Get the test server URL.
     */
    protected function serverUrl(): string
    {
        return sprintf('http://%s:%d', $this->getServerHost(), $this->getServerPort());
    }
}
