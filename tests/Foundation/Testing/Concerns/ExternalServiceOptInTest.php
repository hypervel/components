<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Algolia\AlgoliaSearch\Algolia;
use Algolia\AlgoliaSearch\Api\SearchClient as AlgoliaSearchClient;
use Algolia\AlgoliaSearch\Http\HttpClientInterface;
use Algolia\AlgoliaSearch\Http\Psr7\Response;
use Hypervel\Foundation\Testing\Concerns\InteractsWithAlgolia;
use Hypervel\Foundation\Testing\Concerns\InteractsWithMeilisearch;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Foundation\Testing\Concerns\InteractsWithTypesense;
use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Contracts\IndexesResults;
use Meilisearch\Contracts\TasksResults;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\TimeOutException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

class ExternalServiceOptInTest extends TestCase
{
    /**
     * External-service host keys mutated by these tests.
     *
     * @var list<string>
     */
    private const ENVIRONMENT_KEYS = [
        'REDIS_HOST',
        'MEILISEARCH_HOST',
        'TYPESENSE_HOST',
        'ALGOLIA_APP_ID',
        'ALGOLIA_SECRET',
        'TEST_SERVER_HOST',
        'TEST_TOKEN',
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
        $this->setExternalServiceEnvironmentValue('REDIS_HOST', null);

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
        $this->setExternalServiceEnvironmentValue('REDIS_HOST', '127.0.0.1');

        $harness = new RedisOptInHarness;

        $harness->runSetUp();

        $this->assertSame(1, $harness->flushRedisCalls);
    }

    public function testMeilisearchSkipsBeforeInitializingClientWhenHostIsNotConfigured(): void
    {
        $this->setExternalServiceEnvironmentValue('MEILISEARCH_HOST', null);

        $harness = new MeilisearchOptInHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set MEILISEARCH_HOST to run Meilisearch integration tests');

        try {
            $harness->runSetUp();
        } finally {
            $this->assertSame(0, $harness->initializeClientCalls);
            $this->assertSame('', $harness->prefix());
        }
    }

    public function testMeilisearchAssignsSequentialPrefixBeforeInitializingClient(): void
    {
        $this->setExternalServiceEnvironmentValue('MEILISEARCH_HOST', '127.0.0.1');
        $this->setExternalServiceEnvironmentValue('TEST_TOKEN', null);

        $harness = new MeilisearchOptInHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Meilisearch client initialization stopped.');

        try {
            $harness->runSetUp();
        } finally {
            $this->assertSame('test_', $harness->prefixAtClientInitialization);
        }
    }

    public function testMeilisearchAssignsParallelPrefixBeforeInitializingClient(): void
    {
        $this->setExternalServiceEnvironmentValue('MEILISEARCH_HOST', '127.0.0.1');
        $this->setExternalServiceEnvironmentValue('TEST_TOKEN', '4');

        $harness = new MeilisearchOptInHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Meilisearch client initialization stopped.');

        try {
            $harness->runSetUp();
        } finally {
            $this->assertSame('test_4_', $harness->prefixAtClientInitialization);
        }
    }

    public function testMeilisearchPreservesExplicitPrefixBeforeInitializingClient(): void
    {
        $this->setExternalServiceEnvironmentValue('MEILISEARCH_HOST', '127.0.0.1');
        $this->setExternalServiceEnvironmentValue('TEST_TOKEN', '4');

        $harness = new MeilisearchOptInHarness;
        $harness->usePrefix('custom_');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Meilisearch client initialization stopped.');

        try {
            $harness->runSetUp();
        } finally {
            $this->assertSame('custom_', $harness->prefixAtClientInitialization);
        }
    }

    public function testMeilisearchWaitAcceptsSuccessfulTasks(): void
    {
        $client = m::mock(MeilisearchClient::class);
        $client->shouldReceive('getTasks')
            ->once()
            ->andReturn(new TasksResults([
                'results' => [['uid' => 17, 'indexUid' => 'test_users', 'status' => 'enqueued']],
            ]));
        $client->shouldReceive('waitForTask')
            ->once()
            ->with(17, 5000)
            ->andReturn(['uid' => 17, 'status' => 'succeeded']);

        $harness = new MeilisearchOptInHarness;
        $harness->usePrefix('test_');
        $harness->useClient($client);
        $harness->waitForTasks();
    }

    public function testMeilisearchWaitRejectsFailedTasks(): void
    {
        $client = m::mock(MeilisearchClient::class);
        $client->shouldReceive('getTasks')
            ->once()
            ->andReturn(new TasksResults([
                'results' => [['uid' => 17, 'indexUid' => 'test_users', 'status' => 'processing']],
            ]));
        $client->shouldReceive('waitForTask')
            ->once()
            ->with(17, 5000)
            ->andReturn([
                'uid' => 17,
                'status' => 'failed',
                'error' => ['message' => 'Document identifier is invalid.'],
            ]);

        $harness = new MeilisearchOptInHarness;
        $harness->usePrefix('test_');
        $harness->useClient($client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Document identifier is invalid.');

        $harness->waitForTasks();
    }

    public function testMeilisearchWaitPropagatesTimeouts(): void
    {
        $timeout = new TimeOutException;
        $client = m::mock(MeilisearchClient::class);
        $client->shouldReceive('getTasks')
            ->once()
            ->andReturn(new TasksResults([
                'results' => [['uid' => 17, 'indexUid' => 'test_users', 'status' => 'enqueued']],
            ]));
        $client->shouldReceive('waitForTask')
            ->once()
            ->with(17, 5000)
            ->andThrow($timeout);

        $harness = new MeilisearchOptInHarness;
        $harness->usePrefix('test_');
        $harness->useClient($client);

        $this->expectExceptionObject($timeout);

        $harness->waitForTasks();
    }

    public function testMeilisearchWaitIgnoresPendingTasksForOtherPrefixes(): void
    {
        $client = m::mock(MeilisearchClient::class);
        $client->shouldReceive('getTasks')
            ->once()
            ->andReturn(new TasksResults([
                'results' => [['uid' => 17, 'indexUid' => 'other_users', 'status' => 'enqueued']],
            ]));
        $client->shouldNotReceive('waitForTask');

        $harness = new MeilisearchOptInHarness;
        $harness->usePrefix('test_');
        $harness->useClient($client);
        $harness->waitForTasks();

        $this->assertTrue(true);
    }

    public function testMeilisearchCleanupWaitsForExactDeletionTasks(): void
    {
        $index = m::mock(Indexes::class);
        $index->shouldReceive('getUid')->andReturn('test_users');

        $client = m::mock(MeilisearchClient::class);
        $client->shouldReceive('getIndexes')
            ->once()
            ->andReturn(new IndexesResults([
                'results' => [$index],
                'offset' => 0,
                'limit' => 20,
                'total' => 1,
            ]));
        $client->shouldReceive('deleteIndex')
            ->once()
            ->with('test_users')
            ->andReturn(['taskUid' => 29]);
        $client->shouldReceive('waitForTask')
            ->once()
            ->with(29, 5000)
            ->andReturn(['uid' => 29, 'status' => 'succeeded']);

        $harness = new MeilisearchOptInHarness;
        $harness->usePrefix('test_');
        $harness->useClient($client);
        $harness->runCleanup();
    }

    public function testMeilisearchCleanupPropagatesDeletionTaskFailures(): void
    {
        $index = m::mock(Indexes::class);
        $index->shouldReceive('getUid')->andReturn('test_users');

        $client = m::mock(MeilisearchClient::class);
        $client->shouldReceive('getIndexes')
            ->once()
            ->andReturn(new IndexesResults([
                'results' => [$index],
                'offset' => 0,
                'limit' => 20,
                'total' => 1,
            ]));
        $client->shouldReceive('deleteIndex')
            ->once()
            ->with('test_users')
            ->andReturn(['taskUid' => 29]);
        $client->shouldReceive('waitForTask')
            ->once()
            ->with(29, 5000)
            ->andReturn([
                'uid' => 29,
                'status' => 'failed',
                'error' => ['message' => 'Index deletion failed.'],
            ]);

        $harness = new MeilisearchOptInHarness;
        $harness->usePrefix('test_');
        $harness->useClient($client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Index deletion failed.');

        $harness->runCleanup();
    }

    public function testTypesenseSkipsBeforeInitializingClientWhenHostIsNotConfigured(): void
    {
        $this->setExternalServiceEnvironmentValue('TYPESENSE_HOST', null);

        $harness = new TypesenseOptInHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set TYPESENSE_HOST to run Typesense integration tests');

        try {
            $harness->runSetUp();
        } finally {
            $this->assertSame(0, $harness->initializeClientCalls);
            $this->assertSame('', $harness->prefix());
        }
    }

    public function testTypesenseAssignsSequentialPrefixBeforeInitializingClient(): void
    {
        $this->setExternalServiceEnvironmentValue('TYPESENSE_HOST', '127.0.0.1');
        $this->setExternalServiceEnvironmentValue('TEST_TOKEN', null);

        $harness = new TypesenseOptInHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Typesense client initialization stopped.');

        try {
            $harness->runSetUp();
        } finally {
            $this->assertSame('test_', $harness->prefixAtClientInitialization);
        }
    }

    public function testTypesenseAssignsParallelPrefixBeforeInitializingClient(): void
    {
        $this->setExternalServiceEnvironmentValue('TYPESENSE_HOST', '127.0.0.1');
        $this->setExternalServiceEnvironmentValue('TEST_TOKEN', '4');

        $harness = new TypesenseOptInHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Typesense client initialization stopped.');

        try {
            $harness->runSetUp();
        } finally {
            $this->assertSame('test_4_', $harness->prefixAtClientInitialization);
        }
    }

    public function testTypesensePreservesExplicitPrefixBeforeInitializingClient(): void
    {
        $this->setExternalServiceEnvironmentValue('TYPESENSE_HOST', '127.0.0.1');
        $this->setExternalServiceEnvironmentValue('TEST_TOKEN', '4');

        $harness = new TypesenseOptInHarness;
        $harness->usePrefix('custom_');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Typesense client initialization stopped.');

        try {
            $harness->runSetUp();
        } finally {
            $this->assertSame('custom_', $harness->prefixAtClientInitialization);
        }
    }

    public function testAlgoliaSkipsWhenCredentialsAreNotConfigured(): void
    {
        $this->setExternalServiceEnvironmentValue('ALGOLIA_APP_ID', null);
        $this->setExternalServiceEnvironmentValue('ALGOLIA_SECRET', null);

        $harness = new AlgoliaOptInHarness;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Algolia credentials unavailable. Set ALGOLIA_APP_ID & ALGOLIA_SECRET to enable');

        $harness->runSetUp();
    }

    public function testAlgoliaAssignsADefaultPrefixAndRestoresTheExactHttpClient(): void
    {
        $this->setExternalServiceEnvironmentValue('ALGOLIA_APP_ID', 'application-id');
        $this->setExternalServiceEnvironmentValue('ALGOLIA_SECRET', 'secret');

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
        $this->setExternalServiceEnvironmentValue('ALGOLIA_APP_ID', 'application-id');
        $this->setExternalServiceEnvironmentValue('ALGOLIA_SECRET', 'secret');

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

    public function testAlgoliaCleanupWaitsForExactDeletionTasks(): void
    {
        $client = m::mock(AlgoliaSearchClient::class);
        $client->shouldReceive('listIndices')
            ->once()
            ->andReturn(['items' => [
                ['name' => 'test_users'],
                ['name' => 'production_users'],
            ]]);
        $client->shouldReceive('deleteIndex')
            ->once()
            ->with('test_users')
            ->andReturn(['taskID' => 31]);
        $client->shouldReceive('waitForTask')
            ->once()
            ->with('test_users', 31)
            ->andReturn(['status' => 'published']);

        $harness = new AlgoliaOptInHarness;
        $harness->usePrefix('test_');
        $harness->useClient($client);
        $harness->runCleanup();
    }

    #[DataProvider('incompleteAlgoliaDeletionTasks')]
    public function testAlgoliaCleanupRejectsIncompleteDeletionTasks(?array $task): void
    {
        $client = m::mock(AlgoliaSearchClient::class);
        $client->shouldReceive('listIndices')
            ->once()
            ->andReturn(['items' => [['name' => 'test_users']]]);
        $client->shouldReceive('deleteIndex')
            ->once()
            ->with('test_users')
            ->andReturn(['taskID' => 31]);
        $client->shouldReceive('waitForTask')
            ->once()
            ->with('test_users', 31)
            ->andReturn($task);

        $harness = new AlgoliaOptInHarness;
        $harness->usePrefix('test_');
        $harness->useClient($client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Algolia index deletion task [31] for [test_users] did not complete.');

        $harness->runCleanup();
    }

    /**
     * Provide incomplete Algolia index-deletion tasks.
     *
     * @return array<string, array{null|array{status: string}}>
     */
    public static function incompleteAlgoliaDeletionTasks(): array
    {
        return [
            'swallowed polling failure' => [null],
            'non-published task' => [['status' => 'processing']],
        ];
    }

    public function testAlgoliaCleanupPropagatesDeletionTaskFailures(): void
    {
        $failure = new RuntimeException('Index deletion failed.');
        $client = m::mock(AlgoliaSearchClient::class);
        $client->shouldReceive('listIndices')
            ->once()
            ->andReturn(['items' => [['name' => 'test_users']]]);
        $client->shouldReceive('deleteIndex')
            ->once()
            ->with('test_users')
            ->andReturn(['taskID' => 31]);
        $client->shouldReceive('waitForTask')
            ->once()
            ->with('test_users', 31)
            ->andThrow($failure);

        $harness = new AlgoliaOptInHarness;
        $harness->usePrefix('test_');
        $harness->useClient($client);

        $this->expectExceptionObject($failure);

        $harness->runCleanup();
    }

    public function testAlgoliaRestoresTheExactHttpClientWhenSetupFails(): void
    {
        $this->setExternalServiceEnvironmentValue('ALGOLIA_APP_ID', 'application-id');
        $this->setExternalServiceEnvironmentValue('ALGOLIA_SECRET', 'secret');

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
        $this->setExternalServiceEnvironmentValue('TEST_SERVER_HOST', null);

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
        $this->setExternalServiceEnvironmentValue('TEST_SERVER_HOST', '127.0.0.1');

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
        foreach (self::ENVIRONMENT_KEYS as $key) {
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
    private function setExternalServiceEnvironmentValue(string $key, ?string $value): void
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

    public ?string $prefixAtClientInitialization = null;

    /**
     * Run Meilisearch setup.
     */
    public function runSetUp(): void
    {
        $this->setUpInteractsWithMeilisearch();
    }

    /**
     * Use the given Meilisearch client.
     */
    public function useClient(MeilisearchClient $client): void
    {
        $this->meilisearch = $client;
    }

    /**
     * Wait for Meilisearch tasks to complete.
     */
    public function waitForTasks(): void
    {
        $this->waitForMeilisearchTasks();
    }

    /**
     * Clean up Meilisearch indexes.
     */
    public function runCleanup(): void
    {
        $this->cleanupMeilisearchIndexes();
    }

    /**
     * Initialize the Meilisearch client.
     */
    protected function initializeMeilisearchClient(): void
    {
        ++$this->initializeClientCalls;
        $this->prefixAtClientInitialization = $this->meilisearchTestPrefix;

        throw new RuntimeException('Meilisearch client initialization stopped.');
    }

    /**
     * Use the given index prefix.
     */
    public function usePrefix(string $prefix): void
    {
        $this->meilisearchTestPrefix = $prefix;
    }

    /**
     * Get the current index prefix.
     */
    public function prefix(): string
    {
        return $this->meilisearchTestPrefix;
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

    public ?string $prefixAtClientInitialization = null;

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
        $this->prefixAtClientInitialization = $this->typesenseTestPrefix;

        throw new RuntimeException('Typesense client initialization stopped.');
    }

    /**
     * Use the given collection prefix.
     */
    public function usePrefix(string $prefix): void
    {
        $this->typesenseTestPrefix = $prefix;
    }

    /**
     * Get the current collection prefix.
     */
    public function prefix(): string
    {
        return $this->typesenseTestPrefix;
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
     * Use the given Algolia client.
     */
    public function useClient(AlgoliaSearchClient $client): void
    {
        $this->algolia = $client;
    }

    /**
     * Clean up Algolia indexes.
     */
    public function runCleanup(): void
    {
        $this->cleanupAlgoliaIndices();
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
