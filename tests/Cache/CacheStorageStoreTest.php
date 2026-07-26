<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\Repository;
use Hypervel\Cache\StorageStore;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Cache\Fixtures\ArrayFilesystem;
use Hypervel\Tests\TestCase;

class CacheStorageStoreTest extends TestCase
{
    public function testValuesCanBeStoredAndRetrieved(): void
    {
        $disk = new ArrayFilesystem;
        $store = new StorageStore($disk, 'cache', 'prefix:');

        $this->assertTrue($store->put('foo', 'bar', 60));
        $this->assertSame('bar', $store->get('foo'));
        $this->assertStringStartsWith('cache/', $store->path('foo'));
    }

    public function testExpiredItemsReturnNullAndGetDeleted(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $disk = new ArrayFilesystem;
        $store = new StorageStore($disk, 'cache');

        $store->put('foo', 'bar', 1);

        CarbonImmutable::setTestNow($now->addSeconds(2));

        $this->assertNull($store->get('foo'));
        $this->assertFalse($disk->exists($store->path('foo')));
    }

    public function testAddDoesNotOverwriteExistingValues(): void
    {
        $store = new StorageStore(new ArrayFilesystem, 'cache');

        $this->assertTrue($store->add('foo', 'bar', 60));
        $this->assertFalse($store->add('foo', 'baz', 60));
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testIncrementAndDecrementRetainExpiration(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $store = new StorageStore(new ArrayFilesystem, 'cache');
        $store->put('foo', 5, 60);

        $this->assertSame(7, $store->increment('foo', 2));
        $this->assertSame(4, $store->decrement('foo', 3));

        CarbonImmutable::setTestNow($now->addSeconds(61));

        $this->assertNull($store->get('foo'));
    }

    public function testTouchUpdatesExpiration(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $store = new StorageStore(new ArrayFilesystem, 'cache');
        $store->put('foo', 'bar', 2);

        CarbonImmutable::setTestNow($now->addSecond());

        $this->assertTrue($store->touch('foo', 60));

        CarbonImmutable::setTestNow($now->addSecond());

        $this->assertSame('bar', $store->get('foo'));
    }

    public function testForgetRemovesFlexibleCreatedKeyWhenParentIsMissing(): void
    {
        $disk = new ArrayFilesystem;
        $store = new StorageStore($disk, 'cache');

        $store->put(Repository::FLEXIBLE_CREATED_KEY_PREFIX . 'foo', true, 60);

        $this->assertTrue($store->forget('foo'));
        $this->assertFalse($disk->exists($store->path(Repository::FLEXIBLE_CREATED_KEY_PREFIX . 'foo')));
    }

    public function testForgetPreservesFlexibleCreatedKeyWhenParentDeletionFails(): void
    {
        $disk = new ArrayFilesystem;
        $store = new StorageStore($disk, 'cache');

        $store->put('foo', 'bar', 60);
        $store->put(Repository::FLEXIBLE_CREATED_KEY_PREFIX . 'foo', true, 60);
        $disk->deleteResult = false;

        $this->assertFalse($store->forget('foo'));
        $this->assertTrue($disk->exists($store->path('foo')));
        $this->assertTrue($disk->exists($store->path(Repository::FLEXIBLE_CREATED_KEY_PREFIX . 'foo')));
    }

    public function testFlushRemovesScopedDirectory(): void
    {
        $disk = new ArrayFilesystem;
        $store = new StorageStore($disk, 'cache');

        $store->put('foo', 'bar', 60);
        $disk->put('other/file', 'value');

        $this->assertTrue($store->flush());
        $this->assertNull($store->get('foo'));
        $this->assertTrue($disk->exists('other/file'));
    }

    public function testFlushWithEmptyRootDirectoryDoesNotIssueAnEmptyDelete(): void
    {
        $disk = new ArrayFilesystem;
        $disk->deleteResult = false;

        $this->assertTrue((new StorageStore($disk))->flush());
    }

    public function testExpirationHeaderRemainsTenBytesBeforeSeptember2001(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $disk = new ArrayFilesystem;
        $store = new StorageStore($disk, 'cache');

        $this->assertTrue($store->put('foo', 'bar', 60));
        $this->assertSame('0946684860', substr((string) $disk->get($store->path('foo')), 0, 10));
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testPutNormalizesFilesystemResults(): void
    {
        $disk = new ArrayFilesystem;
        $store = new StorageStore($disk, 'cache');

        $disk->putResult = 'cache/path';
        $this->assertTrue($store->put('foo', 'bar', 60));

        $disk->putResult = false;
        $this->assertFalse($store->put('bar', 'baz', 60));
    }
}
