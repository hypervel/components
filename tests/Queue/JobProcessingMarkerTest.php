<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Cache\Repository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Queue\JobProcessingMarker;
use Hypervel\Tests\TestCase;
use RuntimeException;

class JobProcessingMarkerTest extends TestCase
{
    public function testFailedDeletionRetainsTheMarkerAndCanBeRetried(): void
    {
        $store = new class extends WorkerArrayStore {
            public bool $failDeletion = true;

            /**
             * Simulate a backend that cannot delete an existing key.
             */
            public function forget(string $key): bool
            {
                return $this->failDeletion ? false : parent::forget($key);
            }
        };
        $cache = new Repository($store);
        $cache->put('job-processing:uuid', true, 60);
        $marker = new JobProcessingMarker($cache, 'job-processing:uuid');

        try {
            $marker->clear();
            $this->fail('A retained marker must prevent the handoff.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to clear the job processing marker [job-processing:uuid].', $exception->getMessage());
        }

        $this->assertTrue($cache->has('job-processing:uuid'));
        $store->failDeletion = false;
        $marker->clear();
        $this->assertFalse($cache->has('job-processing:uuid'));
    }
}
