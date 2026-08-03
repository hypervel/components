<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use __PHP_Incomplete_Class;
use Exception;
use Hypervel\Cache\FileStore;
use Hypervel\Cache\SerializableClassPolicy;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Filesystem\LockableFile;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionProperty;
use RuntimeException;
use stdClass;

class CacheFileStoreTest extends TestCase
{
    public function testNullIsReturnedIfFileDoesntExist()
    {
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('get')->will($this->throwException(new FileNotFoundException));
        $store = new FileStore($files, __DIR__);
        $value = $store->get('foo');
        $this->assertNull($value);
    }

    public function testPutCreatesMissingDirectories()
    {
        $files = $this->mockFilesystem();
        $hash = hash('xxh128', 'foo');
        $contents = '0000000000';
        $full_dir = __DIR__ . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $files->expects($this->once())->method('makeDirectory')->with($this->equalTo($full_dir), $this->equalTo(0777), $this->equalTo(true));
        $files->expects($this->once())->method('put')->with($this->equalTo($full_dir . '/' . $hash))->willReturn(strlen($contents));
        $store = new FileStore($files, __DIR__);
        $result = $store->put('foo', $contents, 0);
        $this->assertTrue($result);
    }

    public function testPutWillConsiderZeroAsEternalTime()
    {
        $files = $this->mockFilesystem();

        $hash = hash('xxh128', 'O--L / key');
        $filePath = __DIR__ . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        $ten9s = '9999999999'; // The "forever" time value.
        $fileContents = $ten9s . serialize('gold');
        $exclusiveLock = true;

        $files->expects($this->once())->method('put')->with(
            $this->equalTo($filePath),
            $this->equalTo($fileContents),
            $this->equalTo($exclusiveLock) // Ensure we do lock the file while putting.
        )->willReturn(strlen($fileContents));

        (new FileStore($files, __DIR__))->put('O--L / key', 'gold', 0);
    }

    public function testPutWillConsiderBigValuesAsEternalTime()
    {
        $files = $this->mockFilesystem();

        $hash = hash('xxh128', 'O--L / key');
        $filePath = __DIR__ . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        $ten9s = '9999999999'; // The "forever" time value.
        $fileContents = $ten9s . serialize('gold');

        $files->expects($this->once())->method('put')->with(
            $this->equalTo($filePath),
            $this->equalTo($fileContents),
        );

        (new FileStore($files, __DIR__))->put('O--L / key', 'gold', (int) $ten9s + 1);
    }

    public function testExpiredItemsReturnNullAndGetDeleted()
    {
        $files = $this->mockFilesystem();
        $contents = '0000000000';
        $files->expects($this->once())->method('get')->willReturn($contents);
        $store = $this->getMockBuilder(FileStore::class)->onlyMethods(['forget'])->setConstructorArgs([$files, __DIR__])->getMock();
        $store->expects($this->once())->method('forget');
        $value = $store->get('foo');
        $this->assertNull($value);
    }

    public function testValidItemReturnsContents()
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

    public function testStoreItemProperlyStoresValues()
    {
        $files = $this->mockFilesystem();
        $store = $this->getMockBuilder(FileStore::class)->onlyMethods(['expiration'])->setConstructorArgs([$files, __DIR__])->getMock();
        $store->expects($this->once())->method('expiration')->with($this->equalTo(10))->willReturn(1111111111);
        $contents = '1111111111' . serialize('Hello World');
        $hash = hash('xxh128', 'foo');
        $cache_dir = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $files->expects($this->once())->method('put')->with($this->equalTo(__DIR__ . '/' . $cache_dir . '/' . $hash), $this->equalTo($contents))->willReturn(strlen($contents));
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

    public function testTouchExtendsTtl()
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
            ->with($this->equalTo($ttl))
            ->willReturn($now->addSeconds($ttl)->getTimestamp());
        $store->expects($this->once())
            ->method('getPayload')
            ->with($key)
            ->willReturn(['data' => $content, 'expiration' => $now->addSeconds($ttl)->getTimestamp()]);
        $files->expects($this->once())
            ->method('put')
            ->with(
                $this->equalTo($path),
                $this->equalTo($now->clone()->addSeconds($ttl)->getTimestamp() . serialize($content)),
                $this->equalTo(true)
            )
            ->willReturn(1);

        $this->assertTrue($store->touch($key, $ttl));
    }

    public function testStoreItemProperlySetsPermissions()
    {
        $files = m::mock(Filesystem::class);
        $files->shouldIgnoreMissing();
        $store = new FileStore($files, __DIR__, 0644);
        $hash = hash('xxh128', 'foo');
        $cache_dir = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $files->shouldReceive('put')->withArgs([__DIR__ . '/' . $cache_dir . '/' . $hash, m::any(), m::any()])->andReturnUsing(function ($name, $value) {
            return strlen($value);
        });
        $files->shouldReceive('chmod')->withArgs([__DIR__ . '/' . $cache_dir . '/' . $hash])->andReturnValues(['0600', '0644'])->times(3);
        $files->shouldReceive('chmod')->withArgs([__DIR__ . '/' . $cache_dir . '/' . $hash, 0644])->andReturn(true)->once();
        $result = $store->put('foo', 'foo', 10);
        $this->assertTrue($result);
        $result = $store->put('foo', 'bar', 10);
        $this->assertTrue($result);
        $result = $store->put('foo', 'baz', 10);
        $this->assertTrue($result);
    }

    public function testStoreItemDirectoryProperlySetsPermissions()
    {
        $files = m::mock(Filesystem::class);
        $files->shouldIgnoreMissing();
        $store = new FileStore($files, __DIR__, 0606);
        $hash = hash('xxh128', 'foo');
        $cache_parent_dir = substr($hash, 0, 2);
        $cache_dir = $cache_parent_dir . '/' . substr($hash, 2, 2);

        $files->shouldReceive('put')->withArgs([__DIR__ . '/' . $cache_dir . '/' . $hash, m::any(), m::any()])->andReturnUsing(function ($name, $value) {
            return strlen($value);
        });

        $files->shouldReceive('exists')->withArgs([__DIR__ . '/' . $cache_dir])->andReturn(false)->once();
        $files->shouldReceive('makeDirectory')->withArgs([__DIR__ . '/' . $cache_dir, 0777, true, true])->once();
        $files->shouldReceive('chmod')->withArgs([__DIR__ . '/' . $cache_parent_dir])->andReturn('0600')->once();
        $files->shouldReceive('chmod')->withArgs([__DIR__ . '/' . $cache_parent_dir, 0606])->andReturn(true)->once();
        $files->shouldReceive('chmod')->withArgs([__DIR__ . '/' . $cache_dir])->andReturn('0600')->once();
        $files->shouldReceive('chmod')->withArgs([__DIR__ . '/' . $cache_dir, 0606])->andReturn(true)->once();

        $result = $store->put('foo', 'foo', 10);
        $this->assertTrue($result);
    }

    public function testAddReturnsFalseWhenFileLockCannotBeAcquired(): void
    {
        $tempDir = ParallelTesting::tempDir('CacheFileStoreTest');
        (new Filesystem)->deleteDirectory($tempDir);
        mkdir($tempDir, 0777, true);

        $store = new FileStore(new Filesystem, $tempDir);
        $lockableFile = new LockableFile($store->path('foo'), 'c+');

        try {
            $lockableFile->getExclusiveLock();

            $this->assertFalse($store->add('foo', 'bar', 10));
        } finally {
            $lockableFile->close();
            (new Filesystem)->deleteDirectory($tempDir);
        }
    }

    public function testAddPadsShortTimestampsToTenDigits(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(990464400));
        $tempDir = ParallelTesting::tempDir('CacheFileStoreTest-add-header');
        (new Filesystem)->deleteDirectory($tempDir);
        mkdir($tempDir, 0777, true);

        try {
            $store = new FileStore(new Filesystem, $tempDir);

            $this->assertTrue($store->add('foo', 'bar', 3));
            $this->assertStringStartsWith('0990464403', file_get_contents($store->path('foo')));
            $this->assertSame('bar', $store->get('foo'));
        } finally {
            (new Filesystem)->deleteDirectory($tempDir);
        }
    }

    public function testRefreshReturnsFalseWhenFileLockCannotBeAcquired(): void
    {
        $tempDir = ParallelTesting::tempDir('CacheFileStoreTest-refresh');
        (new Filesystem)->deleteDirectory($tempDir);
        mkdir($tempDir, 0777, true);

        $store = new FileStore(new Filesystem, $tempDir);
        $path = $store->path('foo');
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, (time() + 60) . serialize('owner'));
        $lockableFile = new LockableFile($path, 'c+');

        try {
            $lockableFile->getExclusiveLock();

            $this->assertFalse($store->refreshIfOwned('foo', 'owner', 10));
        } finally {
            $lockableFile->close();
            (new Filesystem)->deleteDirectory($tempDir);
        }
    }

    public function testRefreshPadsShortTimestampsToTenDigits(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(990464400));
        $tempDir = ParallelTesting::tempDir('CacheFileStoreTest-refresh-header');
        (new Filesystem)->deleteDirectory($tempDir);
        mkdir($tempDir, 0777, true);

        try {
            $store = new FileStore(new Filesystem, $tempDir);

            $this->assertTrue($store->put('foo', 'owner', 60));
            $this->assertTrue($store->refreshIfOwned('foo', 'owner', 3));
            $this->assertStringStartsWith('0990464403', file_get_contents($store->path('foo')));
            $this->assertSame('owner', $store->get('foo'));
        } finally {
            (new Filesystem)->deleteDirectory($tempDir);
        }
    }

    public function testForeversAreStoredWithHighTimestamp()
    {
        $files = $this->mockFilesystem();
        $contents = '9999999999' . serialize('Hello World');
        $hash = hash('xxh128', 'foo');
        $cache_dir = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $files->expects($this->once())->method('put')->with($this->equalTo(__DIR__ . '/' . $cache_dir . '/' . $hash), $this->equalTo($contents))->willReturn(strlen($contents));
        $store = new FileStore($files, __DIR__);
        $result = $store->forever('foo', 'Hello World', 10);
        $this->assertTrue($result);
    }

    public function testForeversAreNotRemovedOnIncrement()
    {
        $files = $this->mockFilesystem();
        $contents = '9999999999' . serialize('Hello World');
        $store = new FileStore($files, __DIR__);
        $store->forever('foo', 'Hello World');
        $store->increment('foo');
        $files->expects($this->once())->method('get')->willReturn($contents);
        $this->assertSame('Hello World', $store->get('foo'));
    }

    public function testIncrementExpiredKeys()
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $filePath = $this->getCachePath('foo');
        $files = $this->mockFilesystem();
        $now = CarbonImmutable::now()->getTimestamp();
        $initialValue = ($now - 10) . serialize(77);
        $valueAfterIncrement = '9999999999' . serialize(3);
        $store = new FileStore($files, __DIR__);

        $files->expects($this->once())->method('get')->with($this->equalTo($filePath), $this->equalTo(true))->willReturn($initialValue);
        $files->expects($this->once())->method('put')->with($this->equalTo($filePath), $this->equalTo($valueAfterIncrement));

        $result = $store->increment('foo', 3);
    }

    public function testIncrementCanAtomicallyJump()
    {
        $filePath = $this->getCachePath('foo');
        $files = $this->mockFilesystem();
        $initialValue = '9999999999' . serialize(1);
        $valueAfterIncrement = '9999999999' . serialize(4);
        $store = new FileStore($files, __DIR__);

        $files->expects($this->once())->method('get')->with($this->equalTo($filePath), $this->equalTo(true))->willReturn($initialValue);
        $files->expects($this->once())->method('put')->with($this->equalTo($filePath), $this->equalTo($valueAfterIncrement));

        $result = $store->increment('foo', 3);
        $this->assertEquals(4, $result);
    }

    public function testDecrementCanAtomicallyJump()
    {
        $filePath = $this->getCachePath('foo');

        $files = $this->mockFilesystem();
        $initialValue = '9999999999' . serialize(2);
        $valueAfterIncrement = '9999999999' . serialize(0);
        $store = new FileStore($files, __DIR__);

        $files->expects($this->once())->method('get')->with($this->equalTo($filePath), $this->equalTo(true))->willReturn($initialValue);
        $files->expects($this->once())->method('put')->with($this->equalTo($filePath), $this->equalTo($valueAfterIncrement));

        $result = $store->decrement('foo', 2);
        $this->assertEquals(0, $result);
    }

    public function testIncrementNonNumericValues()
    {
        $filePath = $this->getCachePath('foo');

        $files = $this->mockFilesystem();
        $initialValue = '1999999909' . serialize('foo');
        $valueAfterIncrement = '1999999909' . serialize(1);
        $store = new FileStore($files, __DIR__);
        $files->expects($this->once())->method('get')->with($this->equalTo($filePath), $this->equalTo(true))->willReturn($initialValue);
        $files->expects($this->once())->method('put')->with($this->equalTo($filePath), $this->equalTo($valueAfterIncrement));
        $result = $store->increment('foo');

        $this->assertEquals(1, $result);
    }

    public function testIncrementNonExistentKeys()
    {
        $filePath = $this->getCachePath('foo');

        $files = $this->mockFilesystem();
        $valueAfterIncrement = '9999999999' . serialize(1);
        $store = new FileStore($files, __DIR__);
        // simulates a missing item in file store by the exception
        $files->expects($this->once())->method('get')->with($this->equalTo($filePath), $this->equalTo(true))->willThrowException(new Exception);
        $files->expects($this->once())->method('put')->with($this->equalTo($filePath), $this->equalTo($valueAfterIncrement));
        $result = $store->increment('foo');
        $this->assertIsInt($result);
        $this->assertEquals(1, $result);
    }

    public function testIncrementDoesNotExtendCacheLife()
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
        $files->expects($this->once())->method('put')->with($this->equalTo(__DIR__ . '/' . $cache_dir . '/' . $hash), $this->equalTo($valueAfterIncrement));
        $store->increment('foo');
    }

    public function testRemoveDeletesFileDoesntExist()
    {
        $files = $this->mockFilesystem();
        $hash = hash('xxh128', 'foobull');
        $cache_dir = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $files->expects($this->once())->method('exists')->with($this->equalTo(__DIR__ . '/' . $cache_dir . '/' . $hash))->willReturn(false);
        $store = new FileStore($files, __DIR__);
        $store->forget('foobull');
    }

    public function testRemoveDeletesFile()
    {
        $files = $this->mockFilesystem();
        $hash = hash('xxh128', 'foobar');
        $cache_dir = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $store = new FileStore($files, __DIR__);
        $store->put('foobar', 'Hello Baby', 10);
        $files->expects($this->once())->method('exists')->with($this->equalTo(__DIR__ . '/' . $cache_dir . '/' . $hash))->willReturn(true);
        $files->expects($this->once())->method('delete')->with($this->equalTo(__DIR__ . '/' . $cache_dir . '/' . $hash));
        $store->forget('foobar');
    }

    public function testFlushCleansDirectory()
    {
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('isDirectory')->with($this->equalTo(__DIR__))->willReturn(true);
        $files->expects($this->once())->method('directories')->with($this->equalTo(__DIR__))->willReturn(['foo']);
        $files->expects($this->once())->method('deleteDirectory')->with($this->equalTo('foo'))->willReturn(true);

        $store = new FileStore($files, __DIR__);
        $result = $store->flush();
        $this->assertTrue($result, 'Flush failed');
    }

    public function testFlushFailsDirectoryClean()
    {
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('isDirectory')->with($this->equalTo(__DIR__))->willReturn(true);
        $files->expects($this->once())->method('directories')->with($this->equalTo(__DIR__))->willReturn(['foo']);
        $files->expects($this->once())->method('deleteDirectory')->with($this->equalTo('foo'))->willReturn(false);

        $store = new FileStore($files, __DIR__);
        $result = $store->flush();
        $this->assertFalse($result, 'Flush should not have cleared directories');
    }

    public function testFlushIgnoreNonExistingDirectory()
    {
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('isDirectory')->with($this->equalTo(__DIR__ . '--wrong'))->willReturn(false);

        $store = new FileStore($files, __DIR__ . '--wrong');
        $result = $store->flush();
        $this->assertFalse($result, 'Flush should not clean directory');
    }

    public function testFlushingLocksCleansDirectory()
    {
        $lockDir = __DIR__ . '/locks';
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('isDirectory')->with($this->equalTo($lockDir))->willReturn(true);
        $files->expects($this->once())->method('directories')->with($this->equalTo($lockDir))->willReturn(['foo']);
        $files->expects($this->once())->method('deleteDirectory')->with($this->equalTo('foo'))->willReturn(true);

        $store = new FileStore($files, __DIR__);
        $store->setLockDirectory($lockDir);
        $result = $store->flushLocks();
        $this->assertTrue($result, 'Flushing locks failed');
    }

    public function testFlushingLocksFailsDirectoryClean()
    {
        $lockDir = __DIR__ . '/locks';
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('isDirectory')->with($this->equalTo($lockDir))->willReturn(true);
        $files->expects($this->once())->method('directories')->with($this->equalTo($lockDir))->willReturn(['foo']);
        $files->expects($this->once())->method('deleteDirectory')->with($this->equalTo('foo'))->willReturn(false);

        $store = new FileStore($files, __DIR__);
        $store->setLockDirectory($lockDir);
        $result = $store->flushLocks();
        $this->assertFalse($result, 'Flushing locks should not have cleared directories');
    }

    public function testFlushingLocksIgnoreNonExistingDirectory()
    {
        $lockDir = __DIR__ . '/locks';
        $files = $this->mockFilesystem();
        $files->expects($this->once())->method('isDirectory')->with($this->equalTo($lockDir))->willReturn(false);

        $store = new FileStore($files, __DIR__);
        $store->setLockDirectory($lockDir);
        $result = $store->flushLocks();
        $this->assertFalse($result, 'Flushing locks should not clean locks directory');
    }

    public function testHasSeparateLockStoreReturnsTrueWhenLockDirectoryDiffers()
    {
        $store = new FileStore(new Filesystem, __DIR__);
        $store->setLockDirectory('/locks');

        $this->assertTrue($store->hasSeparateLockStore());
    }

    public function testHasSeparateLockStoreReturnsFalseWhenLockDirectoryIsSame()
    {
        $store = new FileStore(new Filesystem, __DIR__);
        $store->setLockDirectory(__DIR__);

        $this->assertFalse($store->hasSeparateLockStore());
    }

    public function testHasSeparateLockStoreReturnsFalseWhenLockDirectoryIsNull()
    {
        $store = new FileStore(new Filesystem, __DIR__);
        $store->setLockDirectory(null);

        $this->assertFalse($store->hasSeparateLockStore());
    }

    public function testSupportsFlushingLocksRequiresSeparateLockDirectory()
    {
        $store = new FileStore(new Filesystem, __DIR__);

        $this->assertFalse($store->supportsFlushingLocks());

        $store->setLockDirectory('/locks');

        $this->assertTrue($store->supportsFlushingLocks());
    }

    public function testFlushLocksThrowsExceptionWhenLockDirectoryIsSame()
    {
        $store = new FileStore(new Filesystem, __DIR__);
        $store->setLockDirectory(__DIR__);

        $this->expectException(RuntimeException::class);

        $store->flushLocks();
    }

    public function testItHandlesForgettingNonFlexibleKeys()
    {
        $tempDir = ParallelTesting::tempDir('CacheFileStoreTest');
        (new Filesystem)->deleteDirectory($tempDir);
        mkdir($tempDir, 0777, true);

        try {
            $store = new FileStore(new Filesystem, $tempDir);

            $key = Str::random();
            $path = $store->path($key);
            $flexiblePath = $store->path("hypervel:cache:flexible:created:{$key}");

            $store->put($key, 'value', 5);

            $this->assertFileExists($path);
            $this->assertFileDoesNotExist($flexiblePath);

            $store->forget($key);

            $this->assertFileDoesNotExist($path);
            $this->assertFileDoesNotExist($flexiblePath);
        } finally {
            (new Filesystem)->deleteDirectory($tempDir);
        }
    }

    protected function mockFilesystem()
    {
        return $this->createMock(Filesystem::class);
    }

    protected function getCachePath($key)
    {
        $hash = hash('xxh128', $key);
        $cache_dir = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);

        return __DIR__ . '/' . $cache_dir . '/' . $hash;
    }
}
