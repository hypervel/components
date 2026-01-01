<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Testbench\TestCase;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Base test case for Typesense integration tests.
 *
 * Provides parallel-safe Typesense testing infrastructure:
 * - Uses TEST_TOKEN env var (from paratest) to create unique collection prefixes
 * - Configures Typesense client from environment variables
 * - Cleans up test collections in setUp/tearDown
 *
 * NOTE: Concrete test classes extending this MUST add @group integration
 * and @group typesense-integration for proper test filtering in CI.
 *
 * @internal
 * @coversNothing
 */
abstract class TypesenseIntegrationTestCase extends TestCase
{
    use RunTestsInCoroutine;

    /**
     * Base collection prefix for integration tests.
     */
    protected string $basePrefix = 'int_test';

    /**
     * Computed prefix (includes TEST_TOKEN if running in parallel).
     */
    protected string $testPrefix;

    /**
     * The Typesense client instance.
     */
    protected TypesenseClient $typesense;

    /**
     * Track collections created during tests for cleanup.
     *
     * @var array<string>
     */
    protected array $createdCollections = [];

    protected function setUp(): void
    {
        if (! env('RUN_TYPESENSE_INTEGRATION_TESTS', false)) {
            $this->markTestSkipped(
                'Typesense integration tests are disabled. Set RUN_TYPESENSE_INTEGRATION_TESTS=true to enable.'
            );
        }

        $this->computeTestPrefix();

        parent::setUp();

        $this->app->register(ScoutServiceProvider::class);
        $this->configureTypesense();
        $this->typesense = $this->app->get(TypesenseClient::class);
        $this->cleanupTestCollections();
    }

    protected function tearDown(): void
    {
        if (env('RUN_TYPESENSE_INTEGRATION_TESTS', false)) {
            $this->cleanupTestCollections();
        }

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
        $config = $this->app->get(ConfigInterface::class);

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
