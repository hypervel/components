<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Foundation\Testing\Concerns\InteractsWithTypesense;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Testbench\TestCase;
use Throwable;

/**
 * Base test case for Typesense integration tests.
 *
 * Uses InteractsWithTypesense trait for:
 * - Auto-skip: Skips tests if Typesense is unavailable (no env var needed)
 * - Parallel-safe: Uses TEST_TOKEN for unique collection prefixes
 * - Auto-cleanup: Removes test collections in teardown
 *
 * NOTE: This base class does NOT include RunTestsInCoroutine. Subclasses
 * should add the trait if they need coroutine context for their tests.
 *
 * @internal
 * @coversNothing
 */
abstract class TypesenseIntegrationTestCase extends TestCase
{
    use InteractsWithTypesense;

    /**
     * Base collection prefix for integration tests.
     */
    protected string $basePrefix = 'int_test';

    /**
     * Computed prefix (includes TEST_TOKEN if running in parallel).
     */
    protected string $testPrefix;

    /**
     * Track collections created during tests for cleanup.
     *
     * @var array<string>
     */
    protected array $createdCollections = [];

    protected function setUp(): void
    {
        $this->computeTestPrefix();
        $this->typesenseTestPrefix = $this->testPrefix; // Sync trait's prefix

        parent::setUp();

        $this->app->register(ScoutServiceProvider::class);
        $this->configureTypesense();
    }

    /**
     * Initialize the Typesense client and clean up collections.
     *
     * Subclasses using RunTestsInCoroutine should call this in setUpInCoroutine().
     * Subclasses NOT using the trait should call this at the end of setUp().
     *
     * Uses the trait's auto-skip logic - skips if Typesense is unavailable.
     */
    protected function initializeTypesense(): void
    {
        $this->setUpInteractsWithTypesense();
    }

    protected function tearDown(): void
    {
        $this->tearDownInteractsWithTypesense();
        $this->createdCollections = [];

        parent::tearDown();
    }

    /**
     * Compute parallel-safe prefix based on TEST_TOKEN from paratest.
     */
    protected function computeTestPrefix(): void
    {
        $testToken = env('TEST_TOKEN', '');

        if ($testToken !== '') {
            $this->testPrefix = "{$this->basePrefix}_{$testToken}_";
        } else {
            $this->testPrefix = "{$this->basePrefix}_";
        }
    }

    /**
     * Configure Typesense from environment variables.
     */
    protected function configureTypesense(): void
    {
        $config = $this->app->get('config');

        $host = env('TYPESENSE_HOST', '127.0.0.1');
        $port = env('TYPESENSE_PORT', '8108');
        $protocol = env('TYPESENSE_PROTOCOL', 'http');
        $apiKey = env('TYPESENSE_API_KEY', '');

        $config->set('scout.driver', 'typesense');
        $config->set('scout.prefix', $this->testPrefix);
        $config->set('scout.typesense.client-settings', [
            'api_key' => $apiKey,
            'nodes' => [
                [
                    'host' => $host,
                    'port' => $port,
                    'protocol' => $protocol,
                ],
            ],
            'connection_timeout_seconds' => 2,
        ]);
    }

    /**
     * Get a prefixed collection name.
     */
    protected function prefixedCollectionName(string $name): string
    {
        return $this->testPrefix . $name;
    }

    /**
     * Create a test collection and track it for cleanup.
     *
     * @param array<string, mixed> $schema
     */
    protected function createTestCollection(string $name, array $schema): void
    {
        $collectionName = $this->prefixedCollectionName($name);
        $schema['name'] = $collectionName;

        $this->typesense->collections->create($schema);
        $this->createdCollections[] = $collectionName;
    }

    /**
     * Clean up all test collections matching the test prefix.
     */
    protected function cleanupTestCollections(): void
    {
        try {
            $collections = $this->typesense->collections->retrieve();

            foreach ($collections as $collection) {
                if (str_starts_with($collection['name'], $this->testPrefix)) {
                    $this->typesense->collections[$collection['name']]->delete();
                }
            }
        } catch (Throwable) {
            // Ignore errors during cleanup
        }

        $this->createdCollections = [];
    }
}
