<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Algolia\AlgoliaSearch\Algolia;
use Algolia\AlgoliaSearch\Api\SearchClient as AlgoliaSearchClient;
use Algolia\AlgoliaSearch\Http\GuzzleHttpClient;
use Algolia\AlgoliaSearch\Http\HttpClientInterface;
use Algolia\AlgoliaSearch\Model\Search\GetTaskResponse;
use GuzzleHttp\Client as GuzzleClient;
use RuntimeException;
use Throwable;

/**
 * Add Algolia support to an integration test.
 *
 * Use this trait on a test case and set ALGOLIA_APP_ID and ALGOLIA_SECRET.
 * Test indexes are isolated and cleaned up using a TEST_TOKEN-based prefix.
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
                try {
                    $this->cleanupAlgoliaIndices();
                } catch (Throwable) {
                    // Ignore cleanup errors
                }
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

        $tasks = [];
        $indices = $this->algolia->listIndices();

        foreach ($indices['items'] ?? [] as $index) {
            $name = $index['name'] ?? null;

            if (is_string($name) && str_starts_with($name, $this->algoliaTestPrefix)) {
                /** @var array{taskID: int} $task */
                $task = $this->algolia->deleteIndex($name);
                $tasks[] = ['indexName' => $name, 'taskID' => $task['taskID']];
            }
        }

        foreach ($tasks as $task) {
            /** @var null|array<string, mixed>|GetTaskResponse $result */
            $result = $this->algolia->waitForTask($task['indexName'], $task['taskID']);

            if (($result['status'] ?? null) !== 'published') {
                throw new RuntimeException(
                    "Algolia index deletion task [{$task['taskID']}] for [{$task['indexName']}] did not complete."
                );
            }
        }
    }
}
