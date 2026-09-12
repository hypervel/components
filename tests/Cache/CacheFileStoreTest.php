<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use __PHP_Incomplete_Class;
use Exception;
use Hypervel\Cache\FileStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\SerializableClassPolicy;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Filesystem\LockableFile;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;

class CacheFileStoreTest extends TestCase
{
    protected string $tempDir;

    protected Filesystem $filesystem;

    /**
     * Prepare an isolated cache directory.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->tempDir = ParallelTesting::tempDir('CacheFileStoreTest');
        $this->filesystem->deleteDirectory($this->tempDir);
        mkdir($this->tempDir, 0777, true);
    }

    /**
     * Remove the test's cache files.
     */
    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testNullIsReturnedIfFileDoesntExist(): void
    {
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('get')->willThrowException(new FileNotFoundException);
        $store = new FileStore($files, __DIR__);
        $value = $store->get('foo');
        $this->assertNull($value);
    }

    public function testGetPreservesCancellationFromFilesystemRead(): void
    {
        $cancellation = new CanceledException('read canceled');
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('get')->willThrowException($cancellation);
        $store = new FileStore($files, __DIR__);

        try {
            $store->get('foo');
            $this->fail('Reading the cache file was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testRemainingSecondsPreservesCancellationFromFilesystemRead(): void
    {
        $cancellation = new CanceledException('read canceled');
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('get')->willThrowException($cancellation);
        $store = new FileStore($files, __DIR__);

        try {
            $store->remainingSeconds('foo');
            $this->fail('Reading the cache expiry was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testGetPreservesCancellationFromSerializableClassPolicy(): void
    {
        $cancellation = new CanceledException('policy canceled');
        $files = $this->mockFilesystem();
        $files->expects($this->once())
            ->method('get')
            ->willReturn('9999999999' . serialize('value'));
        $store = new FileStore(
            $files,
            __DIR__,
            serializableClassPolicy: new SerializableClassPolicy(
                static fn (): never => throw $cancellation,
            ),
        );

        try {
            $store->get('foo');
            $this->fail('Resolving the serialization policy was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testPutCreatesMissingDirectories(): void
    {
        $files = $this->mockFilesystem();
        $hash = hash('xxh128', 'foo');
        $contents = '0000000000';
        $full_dir = __DIR__ . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $files->expects($this->once())->method('makeDirectory')->with($full_dir, 0777, true);
        $files->expects($this->once())->method('put')->with($full_dir . '/' . $hash)->willReturn(strlen($contents));
        $store = new FileStore($files, __DIR__);
        $result = $store->put('foo', $contents, 0);
        $this->assertTrue($result);
    }

    public function testPutWillConsiderZeroAsEternalTime(): void
    {
        $files = $this->mockFilesystem();

        $hash = hash('xxh128', 'O--L / key');
        $filePath = __DIR__ . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        $ten9s = '9999999999'; // The "forever" time value.
        $fileContents = $ten9s . serialize('gold');
        $exclusiveLock = true;

        $files->expects($this->once())->method('put')->with(
            $filePath,
            $fileContents,
            $exclusiveLock // Ensure we do lock the file while putting.
        )->willReturn(strlen($fileContents));

        (new FileStore($files, __DIR__))->put('O--L / key', 'gold', 0);
    }

    public function testPutWillConsiderBigValuesAsEternalTime(): void
    {
        $files = $this->mockFilesystem();

        $hash = hash('xxh128', 'O--L / key');
        $filePath = __DIR__ . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        $ten9s = '9999999999'; // The "forever" time value.
        $fileContents = $ten9s . serialize('gold');

        $files->expects($this->once())->method('put')->with(
            $filePath,
            $fileContents,
        );

        (new FileStore($files, __DIR__))->put('O--L / key', 'gold', (int) $ten9s + 1);
    }

    public function testExpiredItemsReturnNullAndGetDeleted(): void
    {
        $files = $this->mockFilesystem();
        $contents = '0000000000';
        $files->expects($this->once())->method('get')->willReturn($contents);
        $store = $this->getMockBuilder(FileStore::class)->onlyMethods(['forget'])->setConstructorArgs([$files, __DIR__])->getMock();
        $store->expects($this->once())->method('forget');
        $value = $store->get('foo');
        $this->assertNull($value);
    }

    public function testValidItemReturnsContents(): void
    {
        $files = $this->mockFilesystem();
        $contents = '9999999999' . serialize('Hello World');
        $files->expects($this->once())->method('get')->willReturn($contents);
        $store = new FileStore($files, __DIR__);
        $this->assertSame('Hello World', $store->get('foo'));
    }

    public function testSerializableClassesControlCachedObjects(): void
    {
        $denyingFiles = $this->mockFilesystem();
        $denyingFiles->expects($this->once())
            ->method('get')
            ->willReturn('9999999999' . serialize(new stdClass));
        $allowingFiles = $this->mockFilesystem();
        $allowingFiles->expects($this->once())
            ->method('get')
            ->willReturn('9999999999' . serialize(new stdClass));

        $denyingStore = new FileStore($denyingFiles, __DIR__, null, false);
        $allowingStore = new FileStore(
            $allowingFiles,
            __DIR__,
            serializableClasses: [stdClass::class],
        );

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $denyingStore->get('foo'));
        $this->assertInstanceOf(stdClass::class, $allowingStore->get('foo'));
    }

    public function testSerializableClassPolicyControlsCachedObjects(): void
    {
        $denyingFiles = $this->mockFilesystem();
        $denyingFiles->expects($this->once())
            ->method('get')
            ->willReturn('9999999999' . serialize(new stdClass));
        $allowingFiles = $this->mockFilesystem();
        $allowingFiles->expects($this->once())
            ->method('get')
            ->willReturn('9999999999' . serialize(new stdClass));

        $denyingStore = new FileStore(
            $denyingFiles,
            __DIR__,
            serializableClassPolicy: new SerializableClassPolicy(static fn (): false => false),
        );
        $allowingStore = new FileStore(
            $allowingFiles,
            __DIR__,
            serializableClassPolicy: new SerializableClassPolicy(static fn (): array => [stdClass::class]),
        );

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $denyingStore->get('foo'));
        $this->assertInstanceOf(stdClass::class, $allowingStore->get('foo'));
    }

    public function testLockStoreRetainsBothSerializationPolicies(): void
    {
        $files = m::mock(Filesystem::class)->shouldIgnoreMissing();
        $files->shouldReceive('exists')->andReturnTrue();
        $serializableClasses = [stdClass::class];
        $policy = new SerializableClassPolicy(static fn (): false => false);
        $store = new FileStore(
            $files,
            __DIR__,
            serializableClasses: $serializableClasses,
            serializableClassPolicy: $policy,
        );
        $lock = $store->lock('foo');

        $storeProperty = new ReflectionProperty($lock, 'store');
        $lockStore = $storeProperty->getValue($lock);
        $classesProperty = new ReflectionProperty($lockStore, 'serializableClasses');
        $policyProperty = new ReflectionProperty($lockStore, 'serializableClassPolicy');

        $this->assertSame($serializableClasses, $classesProperty->getValue($lockStore));
        $this->assertSame($policy, $policyProperty->getValue($lockStore));
    }

    public function testStoreItemProperlyStoresValues(): void
    {
        $files = $this->mockFilesystem();
        $store = $this->getMockBuilder(FileStore::class)->onlyMethods(['expiration'])->setConstructorArgs([$files, __DIR__])->getMock();
        $store->expects($this->once())->method('expiration')->with(10)->willReturn(1111111111);
        $contents = '1111111111' . serialize('Hello World');
        $hash = hash('xxh128', 'foo');
        $cache_dir = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $files->expects($this->once())->method('put')->with(__DIR__ . '/' . $cache_dir . '/' . $hash, $contents)->willReturn(strlen($contents));
        $result = $store->put('foo', 'Hello World', 10);
        $this->assertTrue($result);
    }

    public function testPutPadsShortTimestampsToTenDigits(): void
    {
        $files = $this->mockFilesystem();
        $store = $this->getMockBuilder(FileStore::class)->onlyMethods(['expiration'])->setConstructorArgs([$files, __DIR__])->getMock();
        $store->expects($this->once())->method('expiration')->with(3)->willReturn(990464403);
        $contents = '0990464403' . serialize('Hello World');
        $hash = hash('xxh128', 'foo');
        $cacheDir = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $files->expects($this->once())->method('put')->with(__DIR__ . '/' . $cacheDir . '/' . $hash, $contents)->willReturn(strlen($contents));

        $this->assertTrue($store->put('foo', 'Hello World', 3));
    }

    public function testGetPayloadReadsZeroPaddedTimestampsCorrectly(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(990464400));

        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('get')->willReturn('0990464403' . serialize('Hello World'));

        $this->assertSame('Hello World', (new FileStore($files, __DIR__))->get('foo'));
    }

    public function testFractionalSecondWritesPreserveTheRequestedValueAndLockLifetime(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $store = new FileStore($this->filesystem, $this->tempDir);
        $lock = $store->lock('boundary', 1, 'owner');

        $this->assertTrue($store->put('foo', 'bar', 1));
        $this->assertTrue($lock->acquire());
        $this->assertStringStartsWith('0000001002', file_get_contents($store->path('foo')));

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1001.000000'));

        $this->assertSame('bar', $store->get('foo'));
        $this->assertTrue($lock->isOwnedByCurrentProcess());

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1002.000000'));

        $this->assertNull($store->get('foo'));
        $this->assertFalse($lock->isOwnedByCurrentProcess());
    }

    public function testIncrementPreservesAbsoluteExpiryAtFractionalSecond(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $store = new FileStore($this->filesystem, $this->tempDir);

        $this->assertTrue($store->put('counter', 1, 1));
        $this->assertSame(2, $store->increment('counter'));
        $this->assertStringStartsWith('0000001002', file_get_contents($store->path('counter')));

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1001.000000'));

        $this->assertSame(2, $store->get('counter'));

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1002.000000'));

        $this->assertNull($store->get('counter'));
    }

    public function testIncrementSupportsLaravelShapedPayloadOverrides(): void
    {
        $store = new class(new Filesystem, __DIR__) extends FileStore {
            public ?int $writtenDuration = null;

            public ?int $writtenExpiresAt = null;

            /**
             * Record the relative cache lifetime.
             */
            public function put(string $key, mixed $value, int $seconds): bool
            {
                $this->writtenDuration = $seconds;

                return true;
            }

            /**
             * Return a payload with Laravel's remaining lifetime.
             */
            protected function getPayload(string $key): array
            {
                return ['data' => 1, 'time' => 30];
            }

            /**
             * Record the absolute cache expiration.
             */
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

        $store = new class($this->filesystem, $this->tempDir) extends FileStore {
            /**
             * Expose the stored payload and expiration.
             */
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

    public function testTouchExtendsTtl(): void
    {
        $files = $this->mockFilesystem();
        $store = $this->getMockBuilder(FileStore::class)->onlyMethods(['expiration', 'get', 'getPayload'])->setConstructorArgs([$files, __DIR__])->getMock();

        $now = CarbonImmutable::now();

        $key = 'foo';
        $content = 'Hello World';
        $ttl = 60;
        $hash = hash('xxh128', $key);
        $path = __DIR__ . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;

        $store->expects($this->once())
            ->method('expiration')
            ->with($ttl)
            ->willReturn($now->addSeconds($ttl)->getTimestamp());
        $store->expects($this->once())
            ->method('getPayload')
            ->with($key)
            ->willReturn(['data' => $content, 'expiresAt' => $now->addSeconds($ttl)->getTimestamp()]);
        $files->expects($this->once())
            ->method('put')
            ->with(
                $path,
                $now->addSeconds($ttl)->getTimestamp() . serialize($content),
                true
            )
            ->willReturn(1);

        $this->assertTrue($store->touch($key, $ttl));
    }

    public function testStoreItemProperlySetsPermissions(): void
    {
        $files = m::mock(Filesystem::class)->shouldIgnoreMissing();
        $store = new FileStore($files, __DIR__, 0644);
        $hash = hash('xxh128', 'foo');
        $cache_dir = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $files->expects('put')->times(3)->withArgs([__DIR__ . '/' . $cache_dir . '/' . $hash, m::any(), m::any()])->andReturnUsing(function (string $name, string $value): int {
            return strlen($value);
        });
        $files->expects('chmod')->withArgs([__DIR__ . '/' . $cache_dir . '/' . $hash])->andReturnValues(['0600', '0644'])->times(3);
        $files->expects('chmod')->withArgs([__DIR__ . '/' . $cache_dir . '/' . $hash, 0644])->andReturn(true);
        $result = $store->put('foo', 'foo', 10);
        $this->assertTrue($result);
        $result = $store->put('foo', 'bar', 10);
        $this->assertTrue($result);
        $result = $store->put('foo', 'baz', 10);
        $this->assertTrue($result);
    }

    public function testStoreItemDirectoryProperlySetsPermissions(): void
    {
        $files = m::mock(Filesystem::class)->shouldIgnoreMissing();
        $store = new FileStore($files, __DIR__, 0606);
        $hash = hash('xxh128', 'foo');
        $cache_parent_dir = substr($hash, 0, 2);
        $cache_dir = $cache_parent_dir . '/' . substr($hash, 2, 2);

        $files->expects('put')->withArgs([__DIR__ . '/' . $cache_dir . '/' . $hash, m::any(), m::any()])->andReturnUsing(function (string $name, string $value): int {
            return strlen($value);
        });

        $files->expects('exists')->withArgs([__DIR__ . '/' . $cache_dir])->andReturn(false);
        $files->expects('makeDirectory')->withArgs([__DIR__ . '/' . $cache_dir, 0777, true, true]);
        $files->expects('chmod')->withArgs([__DIR__ . '/' . $cache_parent_dir])->andReturn('0600');
        $files->expects('chmod')->withArgs([__DIR__ . '/' . $cache_parent_dir, 0606])->andReturn(true);
        $files->expects('chmod')->withArgs([__DIR__ . '/' . $cache_dir])->andReturn('0600');
        $files->expects('chmod')->withArgs([__DIR__ . '/' . $cache_dir, 0606])->andReturn(true);

        $result = $store->put('foo', 'foo', 10);
        $this->assertTrue($result);
    }

    public function testAddReturnsFalseWhenFileLockCannotBeAcquired(): void
    {
        $store = new FileStore($this->filesystem, $this->tempDir);
        $lockableFile = new LockableFile($store->path('foo'), 'c+');

        try {
            $lockableFile->getExclusiveLock();

            $this->assertFalse($store->add('foo', 'bar', 10));
        } finally {
            $lockableFile->close();
        }
    }

    public function testAddPadsShortTimestampsToTenDigits(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(990464400));

        $store = new FileStore($this->filesystem, $this->tempDir);

        $this->assertTrue($store->add('foo', 'bar', 3));
        $this->assertStringStartsWith('0990464403', file_get_contents($store->path('foo')));
        $this->assertSame('bar', $store->get('foo'));
    }

    public function testRefreshReturnsFalseWhenFileLockCannotBeAcquired(): void
    {
        $store = new FileStore($this->filesystem, $this->tempDir);
        $path = $store->path('foo');
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, (time() + 60) . serialize('owner'));
        $lockableFile = new LockableFile($path, 'c+');

        try {
            $lockableFile->getExclusiveLock();

            $this->assertFalse($store->refreshIfOwned('foo', 'owner', 10));
        } finally {
            $lockableFile->close();
        }
    }

    public function testRefreshPadsShortTimestampsToTenDigits(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(990464400));

        $store = new FileStore($this->filesystem, $this->tempDir);

        $this->assertTrue($store->put('foo', 'owner', 60));
        $this->assertTrue($store->refreshIfOwned('foo', 'owner', 3));
        $this->assertStringStartsWith('0990464403', file_get_contents($store->path('foo')));
        $this->assertSame('owner', $store->get('foo'));
    }

    public function testForeversAreStoredWithHighTimestamp(): void
    {
        $files = $this->mockFilesystem();
        $contents = '9999999999' . serialize('Hello World');
        $hash = hash('xxh128', 'foo');
        $cache_dir = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $files->expects($this->once())->method('put')->with(__DIR__ . '/' . $cache_dir . '/' . $hash, $contents)->willReturn(strlen($contents));
        $store = new FileStore($files, __DIR__);
        $result = $store->forever('foo', 'Hello World');
        $this->assertTrue($result);
    }

    public function testForeversAreNotRemovedOnIncrement(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $store = new FileStore($this->filesystem, $this->tempDir);

        $this->assertTrue($store->forever('counter', 1));
        $this->assertSame(2, $store->increment('counter'));
        $this->assertStringStartsWith('9999999999', file_get_contents($store->path('counter')));
        $this->assertSame(2, $store->get('counter'));
    }

    public function testIncrementExpiredKeys(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $filePath = $this->getCachePath('foo');
        $files = $this->mockFilesystem();
        $now = CarbonImmutable::now()->getTimestamp();
        $initialValue = ($now - 10) . serialize(77);
        $valueAfterIncrement = '9999999999' . serialize(3);
        $store = new FileStore($files, __DIR__);

        $files->expects($this->once())->method('get')->with($filePath, true)->willReturn($initialValue);
        $files->expects($this->once())->method('put')->with($filePath, $valueAfterIncrement);

        $result = $store->increment('foo', 3);
        $this->assertSame(3, $result);
    }

    public function testIncrementCanAtomicallyJump(): void
    {
        $filePath = $this->getCachePath('foo');
        $files = $this->mockFilesystem();
        $initialValue = '9999999999' . serialize(1);
        $valueAfterIncrement = '9999999999' . serialize(4);
        $store = new FileStore($files, __DIR__);

        $files->expects($this->once())->method('get')->with($filePath, true)->willReturn($initialValue);
        $files->expects($this->once())->method('put')->with($filePath, $valueAfterIncrement);

        $result = $store->increment('foo', 3);
        $this->assertSame(4, $result);
    }

    public function testDecrementCanAtomicallyJump(): void
    {
        $filePath = $this->getCachePath('foo');

        $files = $this->mockFilesystem();
        $initialValue = '9999999999' . serialize(2);
        $valueAfterIncrement = '9999999999' . serialize(0);
        $store = new FileStore($files, __DIR__);

        $files->expects($this->once())->method('get')->with($filePath, true)->willReturn($initialValue);
        $files->expects($this->once())->method('put')->with($filePath, $valueAfterIncrement);

        $result = $store->decrement('foo', 2);
        $this->assertSame(0, $result);
    }

    public function testIncrementNonNumericValues(): void
    {
        $filePath = $this->getCachePath('foo');

        $files = $this->mockFilesystem();
        $initialValue = '1999999909' . serialize('foo');
        $valueAfterIncrement = '1999999909' . serialize(1);
        $store = new FileStore($files, __DIR__);
        $files->expects($this->once())->method('get')->with($filePath, true)->willReturn($initialValue);
        $files->expects($this->once())->method('put')->with($filePath, $valueAfterIncrement);
        $result = $store->increment('foo');

        $this->assertSame(1, $result);
    }

    public function testIncrementNonExistentKeys(): void
    {
        $filePath = $this->getCachePath('foo');

        $files = $this->mockFilesystem();
        $valueAfterIncrement = '9999999999' . serialize(1);
        $store = new FileStore($files, __DIR__);
        // simulates a missing item in file store by the exception
        $files->expects($this->once())->method('get')->with($filePath, true)->willThrowException(new Exception);
        $files->expects($this->once())->method('put')->with($filePath, $valueAfterIncrement);
        $result = $store->increment('foo');
        $this->assertIsInt($result);
        $this->assertSame(1, $result);
    }

    public function testIncrementDoesNotExtendCacheLife(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $files = $this->mockFilesystem();
        $expiration = CarbonImmutable::now()->addSeconds(50)->getTimestamp();
        $initialValue = $expiration . serialize(1);
        $valueAfterIncrement = $expiration . serialize(2);
        $store = new FileStore($files, __DIR__);
        $files->expects($this->once())->method('get')->willReturn($initialValue);
        $hash = hash('xxh128', 'foo');
        $cache_dir = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $files->expects($this->once())->method('put')->with(__DIR__ . '/' . $cache_dir . '/' . $hash, $valueAfterIncrement);
        $store->increment('foo');
    }

    public function testRemoveDeletesFileDoesntExist(): void
    {
        $files = $this->mockFilesystem();
        $hash = hash('xxh128', 'foobull');
        $cache_dir = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $files->expects($this->once())->method('exists')->with(__DIR__ . '/' . $cache_dir . '/' . $hash)->willReturn(false);
        $store = new FileStore($files, __DIR__);
        $store->forget('foobull');
    }

    public function testRemoveDeletesFile(): void
    {
        $store = new FileStore($this->filesystem, $this->tempDir);
        $store->put('foobar', 'Hello Baby', 10);

        $this->assertFileExists($store->path('foobar'));

        $store->forget('foobar');

        $this->assertFileDoesNotExist($store->path('foobar'));
    }

    public function testFlushCleansDirectory(): void
    {
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('isDirectory')->with(__DIR__)->willReturn(true);
        $files->expects($this->once())->method('directories')->with(__DIR__)->willReturn(['foo']);
        $files->expects($this->once())->method('deleteDirectory')->with('foo')->willReturn(true);

        $store = new FileStore($files, __DIR__);
        $result = $store->flush();
        $this->assertTrue($result, 'Flush failed');
    }

    public function testFlushFailsDirectoryClean(): void
    {
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('isDirectory')->with(__DIR__)->willReturn(true);
        $files->expects($this->once())->method('directories')->with(__DIR__)->willReturn(['foo']);
        $files->expects($this->once())->method('deleteDirectory')->with('foo')->willReturn(false);

        $store = new FileStore($files, __DIR__);
        $result = $store->flush();
        $this->assertFalse($result, 'Flush should not have cleared directories');
    }

    public function testFlushIgnoreNonExistingDirectory(): void
    {
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('isDirectory')->with(__DIR__ . '--wrong')->willReturn(false);

        $store = new FileStore($files, __DIR__ . '--wrong');
        $result = $store->flush();
        $this->assertFalse($result, 'Flush should not clean directory');
    }

    public function testFlushingLocksCleansDirectory(): void
    {
        $lockDir = __DIR__ . '/locks';
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('isDirectory')->with($lockDir)->willReturn(true);
        $files->expects($this->once())->method('directories')->with($lockDir)->willReturn(['foo']);
        $files->expects($this->once())->method('deleteDirectory')->with('foo')->willReturn(true);

        $store = new FileStore($files, __DIR__);
        $store->setLockDirectory($lockDir);
        $result = $store->flushLocks();
        $this->assertTrue($result, 'Flushing locks failed');
    }

    public function testFlushingLocksFailsDirectoryClean(): void
    {
        $lockDir = __DIR__ . '/locks';
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('isDirectory')->with($lockDir)->willReturn(true);
        $files->expects($this->once())->method('directories')->with($lockDir)->willReturn(['foo']);
        $files->expects($this->once())->method('deleteDirectory')->with('foo')->willReturn(false);

        $store = new FileStore($files, __DIR__);
        $store->setLockDirectory($lockDir);
        $result = $store->flushLocks();
        $this->assertFalse($result, 'Flushing locks should not have cleared directories');
    }

    public function testFlushingLocksIgnoreNonExistingDirectory(): void
    {
        $lockDir = __DIR__ . '/locks';
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('isDirectory')->with($lockDir)->willReturn(false);

        $store = new FileStore($files, __DIR__);
        $store->setLockDirectory($lockDir);
        $result = $store->flushLocks();
        $this->assertFalse($result, 'Flushing locks should not clean locks directory');
    }

    public function testHasSeparateLockStoreReturnsTrueWhenLockDirectoryDiffers(): void
    {
        $store = new FileStore(new Filesystem, __DIR__);
        $store->setLockDirectory('/locks');

        $this->assertTrue($store->hasSeparateLockStore());
    }

    public function testHasSeparateLockStoreReturnsFalseWhenLockDirectoryIsSame(): void
    {
        $store = new FileStore(new Filesystem, __DIR__);
        $store->setLockDirectory(__DIR__);

        $this->assertFalse($store->hasSeparateLockStore());
    }

    public function testHasSeparateLockStoreReturnsFalseWhenLockDirectoryIsNull(): void
    {
        $store = new FileStore(new Filesystem, __DIR__);
        $store->setLockDirectory(null);

        $this->assertFalse($store->hasSeparateLockStore());
    }

    public function testSupportsFlushingLocksRequiresSeparateLockDirectory(): void
    {
        $store = new FileStore(new Filesystem, __DIR__);

        $this->assertFalse($store->supportsFlushingLocks());

        $store->setLockDirectory('/locks');

        $this->assertTrue($store->supportsFlushingLocks());
    }

    public function testFlushLocksThrowsExceptionWhenLockDirectoryIsSame(): void
    {
        $store = new FileStore(new Filesystem, __DIR__);
        $store->setLockDirectory(__DIR__);

        $this->expectException(RuntimeException::class);

        $store->flushLocks();
    }

    public function testItHandlesForgettingNonFlexibleKeys(): void
    {
        $store = new FileStore($this->filesystem, $this->tempDir);

        $key = Str::random();
        $path = $store->path($key);
        $flexiblePath = $store->path(Repository::FLEXIBLE_CREATED_KEY_PREFIX . $key);

        $store->put($key, 'value', 5);

        $this->assertFileExists($path);
        $this->assertFileDoesNotExist($flexiblePath);

        $store->forget($key);

        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($flexiblePath);
    }

    public function testItOnlyForgetsFlexibleKeysIfParentIsForgotten(): void
    {
        $store = new FileStore($this->filesystem, $this->tempDir);

        $key = Str::random();
        $flexibleKey = Repository::FLEXIBLE_CREATED_KEY_PREFIX . $key;
        $path = $store->path($key);
        $flexiblePath = $store->path($flexibleKey);

        $store->put($flexibleKey, 'created', 60);

        $this->assertFileDoesNotExist($path);
        $this->assertFileExists($flexiblePath);

        $this->assertFalse($store->forget($key));

        $this->assertFileDoesNotExist($path);
        $this->assertFileExists($flexiblePath);

        $store->put($key, 'value', 60);

        $this->assertFileExists($path);
        $this->assertFileExists($flexiblePath);

        $this->assertTrue($store->forget($key));

        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($flexiblePath);
    }

    public function testForgetPreservesFlexibleCreatedKeyWhenParentDeletionFails(): void
    {
        $files = $this->mockFilesystem();
        $store = new FileStore($files, $this->tempDir);

        $files->expects($this->once())->method('exists')->with($store->path('foo'))->willReturn(true);
        $files->expects($this->once())->method('delete')->with($store->path('foo'))->willReturn(false);

        $this->assertFalse($store->forget('foo'));
    }

    /**
     * Create a filesystem mock.
     */
    protected function mockFilesystem(): Filesystem&MockObject
    {
        return $this->createMock(Filesystem::class);
    }

    /**
     * Get the hashed path for a cache key.
     */
    protected function getCachePath(string $key): string
    {
        $hash = hash('xxh128', $key);
        $cache_dir = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);

        return __DIR__ . '/' . $cache_dir . '/' . $hash;
    }
}
