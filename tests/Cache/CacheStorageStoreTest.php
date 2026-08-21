<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use __PHP_Incomplete_Class;
use Hypervel\Cache\Repository;
use Hypervel\Cache\SerializableClassPolicy;
use Hypervel\Cache\StorageStore;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Cache\Fixtures\ArrayFilesystem;
use Hypervel\Tests\TestCase;
use stdClass;

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

    public function testPathUsesPrefixInXxh128Digest(): void
    {
        $prefix = 'prefix:';
        $key = 'foo';
        $hash = hash('xxh128', $prefix . $key);
        $store = new StorageStore(new ArrayFilesystem, 'cache', $prefix);

        $this->assertSame(
            'cache/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash,
            $store->path($key),
        );
    }

    public function testSerializableClassesControlCachedObjects(): void
    {
        $denyingStore = new StorageStore(new ArrayFilesystem, 'cache', '', false);
        $allowingStore = new StorageStore(
            new ArrayFilesystem,
            'cache',
            serializableClasses: [stdClass::class],
        );

        $denyingStore->put('object', new stdClass, 60);
        $allowingStore->put('object', new stdClass, 60);

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $denyingStore->get('object'));
        $this->assertInstanceOf(stdClass::class, $allowingStore->get('object'));
    }

    public function testSerializableClassPolicyControlsCachedObjects(): void
    {
        $denyingStore = new StorageStore(
            new ArrayFilesystem,
            'cache',
            serializableClassPolicy: new SerializableClassPolicy(static fn (): false => false),
        );
        $allowingStore = new StorageStore(
            new ArrayFilesystem,
            'cache',
            serializableClassPolicy: new SerializableClassPolicy(static fn (): array => [stdClass::class]),
        );

        $denyingStore->put('object', new stdClass, 60);
        $allowingStore->put('object', new stdClass, 60);

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $denyingStore->get('object'));
        $this->assertInstanceOf(stdClass::class, $allowingStore->get('object'));
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

    public function testIncrementPreservesAbsoluteExpiryAtFractionalSecond(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $disk = new ArrayFilesystem;
        $store = new StorageStore($disk, 'cache');

        $this->assertTrue($store->put('counter', 1, 1));
        $this->assertSame(2, $store->increment('counter'));
        $this->assertStringStartsWith('0000001002', (string) $disk->get($store->path('counter')));

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1001.000000'));

        $this->assertSame(2, $store->get('counter'));

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1002.000000'));

        $this->assertNull($store->get('counter'));
    }

    public function testIncrementPreservesForeverExpiryAtFractionalSecond(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $disk = new ArrayFilesystem;
        $store = new StorageStore($disk, 'cache');

        $this->assertTrue($store->forever('counter', 1));
        $this->assertSame(2, $store->increment('counter'));
        $this->assertStringStartsWith('9999999999', (string) $disk->get($store->path('counter')));
        $this->assertSame(2, $store->get('counter'));
    }

    public function testIncrementSupportsLaravelShapedPayloadOverrides(): void
    {
        $store = new class(new ArrayFilesystem, 'cache') extends StorageStore {
            public ?int $writtenDuration = null;

            public ?int $writtenExpiresAt = null;

            public function put(string $key, mixed $value, int $seconds): bool
            {
                $this->writtenDuration = $seconds;

                return true;
            }

            protected function getPayload(string $key): array
            {
                return ['data' => 1, 'time' => 30];
            }

            protected function putWithExpiresAt(string $key, mixed $value, int $expiresAt): bool
            {
                $this->writtenExpiresAt = $expiresAt;

                return true;
            }
        };

        $this->assertSame(2, $store->increment('counter'));
        $this->assertSame(30, $store->writtenDuration);
        $this->assertNull($store->writtenExpiresAt);
    }

    public function testPayloadRetainsLaravelRemainingTimeAlongsideAbsoluteExpiry(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(1000));
        $store = new class(new ArrayFilesystem, 'cache') extends StorageStore {
            public function payload(string $key): array
            {
                return $this->getPayload($key);
            }
        };

        $this->assertTrue($store->put('key', 'value', 30));
        $this->assertSame([
            'data' => 'value',
            'time' => 30,
            'expiresAt' => 1030,
        ], $store->payload('key'));
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
