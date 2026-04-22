<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Algolia\AlgoliaSearch\Algolia;
use Algolia\AlgoliaSearch\Api\SearchClient as AlgoliaSearchClient;
use Algolia\AlgoliaSearch\Http\GuzzleHttpClient;
use GuzzleHttp\Client as GuzzleClient;
use Hypervel\Config\Repository;
use Throwable;

/**
 * Provides Algolia integration testing support.
 *
 * Auto-called by TestCase via setUpTraits():
 * - setUpInteractsWithAlgolia() runs after app boots
 * - tearDownInteractsWithAlgolia() runs via beforeApplicationDestroyed()
 *
 * Features:
 * - Auto-skip: Skips tests if ALGOLIA_APP_ID/ALGOLIA_SECRET are unset
 * - Explicit-fail: If credentials ARE set but the probe fails, exceptions
 *   propagate (matches InteractsWithTypesense's "explicit config + failure
 *   → rethrow" pattern — we never hide misconfigured credentials)
 * - Parallel-safe: Uses TEST_TOKEN for unique index prefixes
 * - Auto-cleanup: Removes test indexes in teardown
 *
 * Usage: Add `use InteractsWithAlgolia;` to your test case and call
 * configureAlgoliaForTesting() from defineEnvironment().
 *
 * Environment Variables:
 * - ALGOLIA_APP_ID: Application ID (required)
 * - ALGOLIA_SECRET: Admin API key (required)
 * - TEST_TOKEN: Parallel test token from paratest (auto-set)
 */
trait InteractsWithAlgolia
{
    /**
     * Indicates if credentials were unavailable once, skip all subsequent tests.
     */
    private static bool $algoliaConnectionFailed = false;

    /**
     * The test prefix for index isolation.
     */
    protected string $algoliaTestPrefix = '';

    /**
     * The Algolia client instance.
     */
    protected ?AlgoliaSearchClient $algolia = null;

    /**
     * Set up Algolia for testing (auto-called by setUpTraits).
     *
     * Skip conditions (credentials unavailable):
     * - No ALGOLIA_APP_ID / ALGOLIA_SECRET env vars set (or empty)
     *
     * If credentials ARE set but the probe fails, the exception propagates
     * so the test fails loudly. This matches the Typesense trait's
     * "explicit-config-but-failing → rethrow" discipline.
     */
    protected function setUpInteractsWithAlgolia(): void
    {
        if (static::$algoliaConnectionFailed) {
            $this->markTestSkipped(
                'Algolia credentials unavailable. Set ALGOLIA_APP_ID & ALGOLIA_SECRET to enable ' . static::class
            );
        }

        $appId = env('ALGOLIA_APP_ID');
        $secret = env('ALGOLIA_SECRET');

        if ($appId === null || $secret === null || $appId === '' || $secret === '') {
            static::$algoliaConnectionFailed = true;
            $this->markTestSkipped(
                'Algolia credentials unavailable. Set ALGOLIA_APP_ID & ALGOLIA_SECRET to enable ' . static::class
            );
        }

        // Credentials are explicit. Any failure from here on is a real
        // misconfiguration — let it propagate so the test fails loudly.
        Algolia::setHttpClient(new GuzzleHttpClient(new GuzzleClient));
        $this->algolia = AlgoliaSearchClient::create($appId, $secret);
        $this->algolia->listIndices();
        $this->cleanupAlgoliaIndices();
    }

    /**
     * Tear down Algolia (auto-called via beforeApplicationDestroyed).
     */
    protected function tearDownInteractsWithAlgolia(): void
    {
        if (static::$algoliaConnectionFailed || $this->algolia === null) {
            return;
        }

        try {
            $this->cleanupAlgoliaIndices();
        } catch (Throwable) {
            // Ignore cleanup errors
        }

        $this->algolia = null;
    }

    /**
     * Configure Algolia for testing.
     *
     * Call from defineEnvironment() to set up Scout config.
     */
    protected function configureAlgoliaForTesting(Repository $config): void
    {
        $this->computeAlgoliaTestPrefix();

        $config->set('scout.driver', 'algolia');
        $config->set('scout.prefix', $this->algoliaTestPrefix);
        $config->set('scout.algolia.id', env('ALGOLIA_APP_ID', ''));
        $config->set('scout.algolia.secret', env('ALGOLIA_SECRET', ''));
    }

    /**
     * Compute the test prefix for parallel-safe index names.
     */
    protected function computeAlgoliaTestPrefix(): void
    {
        $base = 'test_';
        $token = env('TEST_TOKEN', '');

        $this->algoliaTestPrefix = $token !== '' ? "{$base}{$token}_" : $base;
    }

    /**
     * Get a prefixed index name.
     */
    protected function algoliaIndex(string $name): string
    {
        return $this->algoliaTestPrefix . $name;
    }

    /**
     * Clean up all test indexes matching the test prefix.
     */
    protected function cleanupAlgoliaIndices(): void
    {
        if ($this->algolia === null) {
            return;
        }

        try {
            $indices = $this->algolia->listIndices();

            foreach ($indices['items'] ?? [] as $index) {
                $name = $index['name'] ?? null;

                if (is_string($name) && str_starts_with($name, $this->algoliaTestPrefix)) {
                    $this->algolia->deleteIndex($name);
                }
            }
        } catch (Throwable) {
            // Ignore errors during cleanup
        }
    }
}
