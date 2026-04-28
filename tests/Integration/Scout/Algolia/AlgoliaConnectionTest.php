<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Algolia;

use Hypervel\Tests\Support\AlgoliaIntegrationTestCase;

/**
 * Basic connectivity test for Algolia.
 */
class AlgoliaConnectionTest extends AlgoliaIntegrationTestCase
{
    protected function setUpInCoroutine(): void
    {
        $this->initializeAlgolia();
    }

    public function testCanConnectToAlgolia(): void
    {
        $response = $this->algolia->listIndices();

        $this->assertIsArray($response);
        $this->assertArrayHasKey('items', $response);
    }

    public function testCanCreateAndDeleteIndex(): void
    {
        $indexName = $this->prefixedIndexName('connection_test');

        // Algolia indexes are created implicitly on first write. Save a
        // single object to materialise the index, then assert it appears in
        // listIndices, then delete it.
        $this->algolia->saveObject($indexName, ['objectID' => '1', 'title' => 'hello']);

        $this->waitForIndex($indexName);

        $response = $this->algolia->listIndices();
        $names = collect($response['items'] ?? [])->pluck('name')->all();
        $this->assertContains($indexName, $names);

        $this->algolia->deleteIndex($indexName);
    }

    /**
     * Poll listIndices until the given index appears or timeout.
     *
     * Algolia index creation is eventually consistent — saveObject returns
     * a taskID but the index may not be visible in listIndices immediately.
     */
    protected function waitForIndex(string $name, int $timeoutMs = 10000): void
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            $response = $this->algolia->listIndices();
            $names = collect($response['items'] ?? [])->pluck('name')->all();

            if (in_array($name, $names, true)) {
                return;
            }

            usleep(200_000);
        }
    }
}
