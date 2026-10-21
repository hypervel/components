<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Cache\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Saloon\Cache\Fixtures\Requests\CachedUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;

// Upstream's committed fixture file is overwritten by each case before it is read, so the cases write it to a
// temporary fixture directory instead.
class ResponseCacheMockFixtureTest extends TestCase
{
    /**
     * The filesystem used for fixture files.
     */
    protected Filesystem $files;

    /**
     * The temporary fixture directory.
     */
    protected string $fixturePath;

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->fixturePath = ParallelTesting::tempDir('SaloonResponseCacheMockFixtureTest');
        $this->files->deleteDirectory($this->fixturePath);
        $this->files->ensureDirectoryExists($this->fixturePath);

        Saloon::fixturePath($this->fixturePath);
        Http::preventStrayRequests();
    }

    /**
     * Tear down the test environment.
     */
    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    public function testResponseCacheCanReturnAStaleBodyWhenMocksUseFixturesAndWithoutCacheIsNotUsed(): void
    {
        $this->writeCachedUserRequestFixture(1);

        $mockClient = new MockClient([
            CachedUserRequest::class => MockResponse::fixture('cached-user-request'),
        ]);

        $connector = TestConnector::make();

        $this->assertSame(1, $connector->send(new CachedUserRequest, $mockClient)->json('version'));

        $this->writeCachedUserRequestFixture(2);

        $this->assertSame(1, $connector->send(new CachedUserRequest, $mockClient)->json('version'));
    }

    public function testWithoutCacheOnTheMockClientSkipsResponseCacheSoUpdatedFixtureFilesAreUsed(): void
    {
        $this->writeCachedUserRequestFixture(1);

        $mockClient = (new MockClient([
            CachedUserRequest::class => MockResponse::fixture('cached-user-request'),
        ]))->withoutCache();

        $connector = TestConnector::make();

        $this->assertSame(1, $connector->send(new CachedUserRequest, $mockClient)->json('version'));

        $this->writeCachedUserRequestFixture(2);

        $this->assertSame(2, $connector->send(new CachedUserRequest, $mockClient)->json('version'));
    }

    /**
     * Write the cached user request fixture.
     */
    protected function writeCachedUserRequestFixture(int $version): void
    {
        $payload = [
            'statusCode' => 200,
            'headers' => ['Content-Type' => ['application/json']],
            'data' => json_encode(['version' => $version]),
            'context' => [],
        ];

        $this->files->put(
            $this->fixturePath . '/cached-user-request.json',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
    }
}
