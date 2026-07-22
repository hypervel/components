<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithTypesense;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Testbench\TestCase;

/**
 * Base test case for Typesense integration tests.
 *
 * Uses InteractsWithTypesense trait for:
 * - Opt-in skip: Skips unless TYPESENSE_HOST is set
 * - Parallel-safe: Uses TEST_TOKEN for unique collection prefixes
 * - Auto-cleanup: Removes test collections in teardown
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

    protected function setUp(): void
    {
        $this->computeTestPrefix();
        $this->typesenseTestPrefix = $this->testPrefix; // Sync trait's prefix

        parent::setUp();
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            ScoutServiceProvider::class,
        ];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $this->configureTypesense($app);
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
    protected function configureTypesense(ApplicationContract $app): void
    {
        $config = $app->make('config');

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
}
