<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithAlgolia;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Testbench\TestCase;

/**
 * Base test case for Algolia integration tests.
 *
 * Uses InteractsWithAlgolia trait for:
 * - Opt-in skip: Skips unless ALGOLIA_APP_ID and ALGOLIA_SECRET are set
 * - Explicit-fail: Credentials set but probe fails → exception propagates
 * - Parallel-safe: Uses TEST_TOKEN for unique index prefixes
 * - Auto-cleanup: Removes test indexes in teardown
 */
abstract class AlgoliaIntegrationTestCase extends TestCase
{
    use InteractsWithAlgolia;

    /**
     * Base index prefix for integration tests.
     */
    protected string $basePrefix = 'int_test';

    /**
     * Computed prefix (includes TEST_TOKEN if running in parallel).
     */
    protected string $testPrefix;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            ScoutServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        $this->computeTestPrefix();
        $this->algoliaTestPrefix = $this->testPrefix;

        parent::setUp();

        $this->configureAlgolia();
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
     * Configure Algolia from environment variables.
     */
    protected function configureAlgolia(): void
    {
        $config = $this->app->make('config');

        $config->set('scout.driver', 'algolia');
        $config->set('scout.prefix', $this->testPrefix);
        $config->set('scout.algolia.id', env('ALGOLIA_APP_ID', ''));
        $config->set('scout.algolia.secret', env('ALGOLIA_SECRET', ''));
    }

    /**
     * Get a prefixed index name.
     */
    protected function prefixedIndexName(string $name): string
    {
        return $this->testPrefix . $name;
    }
}
