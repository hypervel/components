<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\ArrayStore;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\parallel;

class CacheArrayStoreCoroutineIsolationTest extends TestCase
{
    public function testArrayValuesAreIsolatedBetweenCoroutines(): void
    {
        $store = new ArrayStore;

        $results = parallel([
            'first' => function () use ($store) {
                $store->put('key', 'first', 60);
                usleep(1000);

                return $store->get('key');
            },
            'second' => function () use ($store) {
                $store->put('key', 'second', 60);
                usleep(1000);

                return $store->get('key');
            },
        ]);

        $this->assertSame('first', $results['first']);
        $this->assertSame('second', $results['second']);
        $this->assertNull($store->get('key'));
    }

    public function testArrayLocksAreIsolatedBetweenCoroutines(): void
    {
        $store = new ArrayStore;

        $results = parallel([
            'first' => function () use ($store) {
                $lock = $store->lock('shared', 60);

                return [$lock->acquire(), $lock->isOwnedByCurrentProcess()];
            },
            'second' => function () use ($store) {
                $lock = $store->lock('shared', 60);

                return [$lock->acquire(), $lock->isOwnedByCurrentProcess()];
            },
        ]);

        $this->assertSame([true, true], $results['first']);
        $this->assertSame([true, true], $results['second']);
    }

    public function testArrayTagsAreIsolatedBetweenCoroutines(): void
    {
        $store = new ArrayStore;

        $results = parallel([
            'first' => function () use ($store) {
                $store->tags('tenant')->put('key', 'first', 60);
                usleep(1000);

                return $store->tags('tenant')->get('key');
            },
            'second' => function () use ($store) {
                $store->tags('tenant')->put('key', 'second', 60);
                usleep(1000);

                return $store->tags('tenant')->get('key');
            },
        ]);

        $this->assertSame('first', $results['first']);
        $this->assertSame('second', $results['second']);
        $this->assertNull($store->tags('tenant')->get('key'));
    }

    public function testCopiedCoroutineContextCopiesArrayCacheValuesWithoutSharingFutureWrites(): void
    {
        $store = new ArrayStore;
        $store->put('key', 'parent', 60);

        $results = parallel([
            'child' => function () use ($store) {
                $before = $store->get('key');
                $store->put('key', 'child', 60);

                return [$before, $store->get('key')];
            },
        ], copyContext: true);

        $this->assertSame(['parent', 'child'], $results['child']);
        $this->assertSame('parent', $store->get('key'));
    }
}
