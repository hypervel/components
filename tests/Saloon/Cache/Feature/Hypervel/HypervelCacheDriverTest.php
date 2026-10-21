<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Cache\Feature\Hypervel;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Saloon\Cache\Fixtures\Requests\HypervelCachedUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;

// The request selects the framework file store with cacheStore() in place of the plugin's Laravel cache driver. The
// store uses a temporary directory, so cached responses are serialized to disk and read back.
class HypervelCacheDriverTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Define the environment setup.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('cache.stores.file', [
            'driver' => 'file',
            'path' => $this->cachePath(),
            'lock_path' => $this->cachePath(),
        ]);
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new Filesystem)->deleteDirectory($this->cachePath());

        Http::preventStrayRequests();
    }

    /**
     * Tear down the test environment.
     */
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->cachePath());

        parent::tearDown();
    }

    public function testItWillReturnACachedResponse(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $connector = new TestConnector;

        $requestA = new HypervelCachedUserRequest;
        $responseA = $connector->send($requestA, $mockClient);

        $this->assertFalse($responseA->isCached());
        $this->assertSame(['name' => 'Sam'], $responseA->json());

        $requestB = new HypervelCachedUserRequest;
        $responseB = $connector->send($requestB);

        $this->assertTrue($responseB->isCached());
        $this->assertSame(['name' => 'Sam'], $responseB->json());
    }

    /**
     * Get the temporary file cache directory.
     */
    protected function cachePath(): string
    {
        return ParallelTesting::tempDir('SaloonHypervelCacheDriverTest');
    }
}
