<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\MemoizedStore;
use Hypervel\Cache\Repository;
use Hypervel\Support\Carbon;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class CacheMemoizedStoreTest extends TestCase
{
    public function testTouchExtendsTtl()
    {
        $store = new MemoizedStore('test', new Repository(new ArrayStore()));

        Carbon::setTestNow($now = Carbon::now());

        $store->put('foo', 'bar', 30);
        $store->touch('foo', 60);

        Carbon::setTestNow($now->addSeconds(45));

        $this->assertSame('bar', $store->get('foo'));
    }
}
