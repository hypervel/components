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
 * - Auto-skip: Skips tests when ALGOLIA_APP_ID/ALGOLIA_SECRET are unset
 * - Explicit-fail: Credentials set but probe fails → exception propagates
 * - Parallel-safe: Uses TEST_TOKEN for unique index prefixes
 * - Auto-cleanup: Removes test indexes in teardown
 *
 * NOTE: This base class does NOT include RunTestsInCoroutine. Subclasses
 * should add the trait if they need coroutine context for their tests.
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
     * Initialize the Algolia client and clean up stale test indexes.
     *
     * Subclasses using RunTestsInCoroutine should call this in setUpInCoroutine().
     * Subclasses NOT using the trait should call this at the end of setUp().
     *
     * Uses the trait's skip logic — skips if credentials are absent.
     */
    protected function initializeAlgolia(): void
    {
        $this->setUpInteractsWithAlgolia();
    }

    protected function tearDown(): void
    {
        $this->tearDownInteractsWithAlgolia();

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
