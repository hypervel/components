<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Algolia\AlgoliaSearch\Algolia;
use Algolia\AlgoliaSearch\Api\SearchClient as AlgoliaSearchClient;
use Algolia\AlgoliaSearch\Http\GuzzleHttpClient;
use Algolia\AlgoliaSearch\Http\HttpClientInterface;
use GuzzleHttp\Client as GuzzleClient;
use Throwable;

/**
 * Provides Algolia integration testing support.
 *
 * Auto-called by TestCase via setUpTraits():
 * - setUpInteractsWithAlgolia() runs after app boots
 * - tearDownInteractsWithAlgolia() runs via beforeApplicationDestroyed()
 *
 * Features:
 * - Opt-in skip: Skips unless ALGOLIA_APP_ID and ALGOLIA_SECRET are set
 * - Explicit-fail: If credentials are set but the probe fails, exceptions
 *   propagate so misconfigured credentials are never hidden
 * - Parallel-safe: Uses TEST_TOKEN for unique index prefixes
 * - Auto-cleanup: Removes test indexes in teardown
 *
 * Usage: Add `use InteractsWithAlgolia;` to your test case.
 *
 * Environment Variables:
 * - ALGOLIA_APP_ID: Application ID (required)
 * - ALGOLIA_SECRET: Admin API key (required)
 * - TEST_TOKEN: Parallel test token from paratest (auto-set)
 */
trait InteractsWithAlgolia
{
    /**
     * The test prefix for index isolation.
     */
    protected string $algoliaTestPrefix = '';

    /**
     * The Algolia client instance.
     */
    protected ?AlgoliaSearchClient $algolia = null;

    /**
     * The HTTP client installed before this test.
     */
    protected ?HttpClientInterface $previousAlgoliaHttpClient = null;

    /**
     * Set up Algolia for testing (auto-called by setUpTraits).
     *
     * Algolia integration tests are opt-in via ALGOLIA_APP_ID and
     * ALGOLIA_SECRET. If credentials are set but the probe fails, the
     * exception propagates so the test fails loudly.
     */
    protected function setUpInteractsWithAlgolia(): void
    {
        $appId = env('ALGOLIA_APP_ID');
        $secret = env('ALGOLIA_SECRET');

        if ($appId === null || $secret === null || $appId === '' || $secret === '') {
            $this->markTestSkipped(
                'Algolia credentials unavailable. Set ALGOLIA_APP_ID & ALGOLIA_SECRET to enable ' . static::class
            );
        }

        if ($this->algoliaTestPrefix === '') {
            $this->computeAlgoliaTestPrefix();
        }

        // Credentials are explicit. Any failure from here on is a real
        // misconfiguration — let it propagate so the test fails loudly.
        $this->previousAlgoliaHttpClient = Algolia::getHttpClient();

        try {
            Algolia::setHttpClient($this->createAlgoliaHttpClient());
            $this->algolia = AlgoliaSearchClient::create($appId, $secret);
            $this->algolia->listIndices();
            $this->cleanupAlgoliaIndices();
        } catch (Throwable $throwable) {
            Algolia::setHttpClient($this->previousAlgoliaHttpClient);
            $this->previousAlgoliaHttpClient = null;
            $this->algolia = null;

            throw $throwable;
        }
    }

    /**
     * Tear down Algolia (auto-called via beforeApplicationDestroyed).
     */
    protected function tearDownInteractsWithAlgolia(): void
    {
        try {
            if ($this->algolia !== null) {
                $this->cleanupAlgoliaIndices();
            }
        } finally {
            $this->algolia = null;

            if ($this->previousAlgoliaHttpClient !== null) {
                Algolia::setHttpClient($this->previousAlgoliaHttpClient);
                $this->previousAlgoliaHttpClient = null;
            }
        }
    }

    /**
     * Create the Algolia HTTP client.
     */
    protected function createAlgoliaHttpClient(): HttpClientInterface
    {
        return new GuzzleHttpClient(new GuzzleClient);
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
