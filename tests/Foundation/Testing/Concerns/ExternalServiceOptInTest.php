<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Algolia\AlgoliaSearch\Algolia;
use Algolia\AlgoliaSearch\Http\HttpClientInterface;
use Algolia\AlgoliaSearch\Http\Psr7\Response;
use Hypervel\Foundation\Testing\Concerns\InteractsWithAlgolia;
use Hypervel\Foundation\Testing\Concerns\InteractsWithMeilisearch;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Foundation\Testing\Concerns\InteractsWithTypesense;
use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Throwable;

class ExternalServiceOptInTest extends TestCase
{
    /**
     * External-service host keys mutated by these tests.
     *
     * @var list<string>
     */
    private const HOST_ENVIRONMENT_KEYS = [
        'REDIS_HOST',
        'MEILISEARCH_HOST',
        'TYPESENSE_HOST',
        'ALGOLIA_APP_ID',
        'ALGOLIA_SECRET',
        'TEST_SERVER_HOST',
    ];

    /**
     * Original environment values.
     *
     * @var array<string, array{process: false|string, server_exists: bool, server: mixed, environment_exists: bool, environment: mixed}>
     */
    private array $originalEnvironmentValues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureEnvironmentValues();
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironmentValues();

        parent::tearDown();
    }

    public function testRedisSkipsBeforeFlushingWhenHostIsNotConfigured(): void
    {
        $this->setHostEnvironmentValue('REDIS_HOST', null);

        $harness = new RedisOptInHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set REDIS_HOST to run Redis integration tests');

        try {
            $harness->runSetUp();
        } finally {
            $this->assertSame(0, $harness->flushRedisCalls);
        }
    }

    public function testRedisRunsWhenHostIsConfigured(): void
    {
        $this->setHostEnvironmentValue('REDIS_HOST', '127.0.0.1');

        $harness = new RedisOptInHarness;

        $harness->runSetUp();

        $this->assertSame(1, $harness->flushRedisCalls);
    }

    public function testMeilisearchSkipsBeforeInitializingClientWhenHostIsNotConfigured(): void
    {
        $this->setHostEnvironmentValue('MEILISEARCH_HOST', null);

        $harness = new MeilisearchOptInHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set MEILISEARCH_HOST to run Meilisearch integration tests');

        try {
            $harness->runSetUp();
        } finally {
            $this->assertSame(0, $harness->initializeClientCalls);
        }
    }

    public function testTypesenseSkipsBeforeInitializingClientWhenHostIsNotConfigured(): void
    {
        $this->setHostEnvironmentValue('TYPESENSE_HOST', null);

        $harness = new TypesenseOptInHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set TYPESENSE_HOST to run Typesense integration tests');

        try {
            $harness->runSetUp();
        } finally {
            $this->assertSame(0, $harness->initializeClientCalls);
        }
    }

    public function testAlgoliaSkipsWhenCredentialsAreNotConfigured(): void
    {
        $this->setHostEnvironmentValue('ALGOLIA_APP_ID', null);
        $this->setHostEnvironmentValue('ALGOLIA_SECRET', null);

        $harness = new AlgoliaOptInHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Algolia credentials unavailable. Set ALGOLIA_APP_ID & ALGOLIA_SECRET to enable');

        $harness->runSetUp();
    }

    public function testAlgoliaAssignsADefaultPrefixAndRestoresTheExactHttpClient(): void
    {
        $this->setHostEnvironmentValue('ALGOLIA_APP_ID', 'application-id');
        $this->setHostEnvironmentValue('ALGOLIA_SECRET', 'secret');

        $testToken = (string) env('TEST_TOKEN', '');
        $expectedPrefix = $testToken === '' ? 'test_' : "test_{$testToken}_";

        $originalClient = Algolia::getHttpClient();
        $previousClient = m::mock(HttpClientInterface::class);
        $testClient = m::mock(HttpClientInterface::class);
        $testClient->shouldReceive('sendRequest')->times(3)->andReturn(
            new Response(200, ['content-type' => 'application/json'], '{"items":[]}')
        );

        Algolia::setHttpClient($previousClient);

        try {
            $harness = new AlgoliaOptInHarness;
            $harness->useHttpClient($testClient);
            $harness->runSetUp();

            $this->assertSame($expectedPrefix, $harness->prefix());
            $this->assertSame($testClient, Algolia::getHttpClient());

            $harness->runTearDown();

            $this->assertSame($previousClient, Algolia::getHttpClient());
            $this->assertTrue($harness->hasClearedState());
        } finally {
            Algolia::setHttpClient($originalClient);
        }
    }

    public function testAlgoliaPreservesAnExplicitPrefix(): void
    {
        $this->setHostEnvironmentValue('ALGOLIA_APP_ID', 'application-id');
        $this->setHostEnvironmentValue('ALGOLIA_SECRET', 'secret');

        $originalClient = Algolia::getHttpClient();
        $testClient = m::mock(HttpClientInterface::class);
        $testClient->shouldReceive('sendRequest')->times(3)->andReturn(
            new Response(200, ['content-type' => 'application/json'], '{"items":[]}')
        );

        try {
            $harness = new AlgoliaOptInHarness;
            $harness->usePrefix('custom_');
            $harness->useHttpClient($testClient);
            $harness->runSetUp();

            $this->assertSame('custom_', $harness->prefix());

            $harness->runTearDown();
        } finally {
            Algolia::setHttpClient($originalClient);
        }
    }

    public function testAlgoliaRestoresTheExactHttpClientWhenSetupFails(): void
    {
        $this->setHostEnvironmentValue('ALGOLIA_APP_ID', 'application-id');
        $this->setHostEnvironmentValue('ALGOLIA_SECRET', 'secret');

        $originalClient = Algolia::getHttpClient();
        $previousClient = m::mock(HttpClientInterface::class);
        $failure = new RuntimeException('Algolia probe failed');
        $testClient = m::mock(HttpClientInterface::class);
        $testClient->shouldReceive('sendRequest')->andThrow($failure);

        Algolia::setHttpClient($previousClient);

        try {
            $harness = new AlgoliaOptInHarness;
            $harness->useHttpClient($testClient);
            $caught = null;

            try {
                $harness->runSetUp();
            } catch (Throwable $throwable) {
                $caught = $throwable;
            }

            $this->assertNotNull($caught);
            $this->assertSame($previousClient, Algolia::getHttpClient());
            $this->assertTrue($harness->hasClearedState());
        } finally {
            Algolia::setHttpClient($originalClient);
        }
    }

    public function testServerSkipsBeforeProbingSocketWhenHostIsNotConfigured(): void
    {
        $this->setHostEnvironmentValue('TEST_SERVER_HOST', null);

        $harness = new ServerOptInHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set TEST_SERVER_HOST to run server integration tests');

        try {
            $harness->runSetUp();
        } finally {
            $this->assertSame(0, $harness->connectionChecks);
        }
    }

    public function testServerFailsWhenConfiguredHostIsUnavailable(): void
    {
        $this->setHostEnvironmentValue('TEST_SERVER_HOST', '127.0.0.1');

        $harness = new ServerOptInHarness;
        $harness->canConnect = false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot connect to server at 127.0.0.1:19510.');

        try {
            $harness->runSetUp();
        } finally {
            $this->assertSame(1, $harness->connectionChecks);
        }
    }

    /**
     * Capture original environment values.
     */
    private function captureEnvironmentValues(): void
    {
        foreach (self::HOST_ENVIRONMENT_KEYS as $key) {
            $this->originalEnvironmentValues[$key] = [
                'process' => getenv($key),
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
                'environment_exists' => array_key_exists($key, $_ENV),
                'environment' => $_ENV[$key] ?? null,
            ];
        }
    }

    /**
     * Set an environment value.
     */
    private function setHostEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_SERVER[$key], $_ENV[$key]);
        } else {
            putenv("{$key}={$value}");
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
        }

        Env::flushRepository();
    }

    /**
     * Restore original environment values.
     */
    private function restoreEnvironmentValues(): void
    {
        foreach ($this->originalEnvironmentValues as $key => $value) {
            $value['process'] === false
                ? putenv($key)
                : putenv("{$key}={$value['process']}");

            if ($value['server_exists']) {
                $_SERVER[$key] = $value['server'];
            } else {
                unset($_SERVER[$key]);
            }

            if ($value['environment_exists']) {
                $_ENV[$key] = $value['environment'];
            } else {
                unset($_ENV[$key]);
            }
        }

        Env::flushRepository();
    }
}

class RedisOptInHarness
{
    use InteractsWithRedis;

    public int $flushRedisCalls = 0;

    /**
     * Run Redis setup.
     */
    public function runSetUp(): void
    {
        $this->setUpInteractsWithRedis();
    }

    /**
     * Flush the Redis database.
     */
    protected function flushRedis(): void
    {
        ++$this->flushRedisCalls;
    }

    /**
     * Get the current parallel testing token.
     */
    protected function parallelTestingToken(): string|false
    {
        return false;
    }

    /**
     * Override skip handling so skip behavior can be asserted.
     */
    protected function markTestSkipped(string $message = ''): never
    {
        throw new RuntimeException($message);
    }
}

class MeilisearchOptInHarness
{
    use InteractsWithMeilisearch;

    public int $initializeClientCalls = 0;

    /**
     * Run Meilisearch setup.
     */
    public function runSetUp(): void
    {
        $this->setUpInteractsWithMeilisearch();
    }

    /**
     * Initialize the Meilisearch client.
     */
    protected function initializeMeilisearchClient(): void
    {
        ++$this->initializeClientCalls;
    }

    /**
     * Override skip handling so skip behavior can be asserted.
     */
    protected function markTestSkipped(string $message = ''): never
    {
        throw new RuntimeException($message);
    }
}

class TypesenseOptInHarness
{
    use InteractsWithTypesense;

    public int $initializeClientCalls = 0;

    /**
     * Run Typesense setup.
     */
    public function runSetUp(): void
    {
        $this->setUpInteractsWithTypesense();
    }

    /**
     * Initialize the Typesense client.
     */
    protected function initializeTypesenseClient(): void
    {
        ++$this->initializeClientCalls;
    }

    /**
     * Override skip handling so skip behavior can be asserted.
     */
    protected function markTestSkipped(string $message = ''): never
    {
        throw new RuntimeException($message);
    }
}

class AlgoliaOptInHarness
{
    use InteractsWithAlgolia;

    protected ?HttpClientInterface $httpClient = null;

    /**
     * Run Algolia setup.
     */
    public function runSetUp(): void
    {
        $this->setUpInteractsWithAlgolia();
    }

    /**
     * Run Algolia teardown.
     */
    public function runTearDown(): void
    {
        $this->tearDownInteractsWithAlgolia();
    }

    /**
     * Use the given Algolia HTTP client.
     */
    public function useHttpClient(HttpClientInterface $httpClient): void
    {
        $this->httpClient = $httpClient;
    }

    /**
     * Use the given index prefix.
     */
    public function usePrefix(string $prefix): void
    {
        $this->algoliaTestPrefix = $prefix;
    }

    /**
     * Get the current index prefix.
     */
    public function prefix(): string
    {
        return $this->algoliaTestPrefix;
    }

    /**
     * Determine if all Algolia lifecycle state has been cleared.
     */
    public function hasClearedState(): bool
    {
        return $this->algolia === null && $this->previousAlgoliaHttpClient === null;
    }

    /**
     * Create the Algolia HTTP client.
     */
    protected function createAlgoliaHttpClient(): HttpClientInterface
    {
        return $this->httpClient ?? throw new RuntimeException('No Algolia HTTP client configured.');
    }

    /**
     * Override skip handling so skip behavior can be asserted.
     */
    protected function markTestSkipped(string $message = ''): never
    {
        throw new RuntimeException($message);
    }
}

class ServerOptInHarness
{
    use InteractsWithServer;

    public bool $canConnect = true;

    public int $connectionChecks = 0;

    protected int $serverPort = 19510;

    /**
     * Run server setup.
     */
    public function runSetUp(): void
    {
        $this->setUpInteractsWithServer();
    }

    /**
     * Check if we can connect to the server.
     */
    protected function canConnectToServer(): bool
    {
        ++$this->connectionChecks;

        return $this->canConnect;
    }

    /**
     * Override skip handling so skip behavior can be asserted.
     */
    protected function markTestSkipped(string $message = ''): never
    {
        throw new RuntimeException($message);
    }

    /**
     * Override failure handling so the failure can be asserted.
     */
    protected function fail(string $message = ''): never
    {
        throw new RuntimeException($message);
    }
}
