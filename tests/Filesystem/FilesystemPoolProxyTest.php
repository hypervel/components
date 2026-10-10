<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use BadMethodCallException;
use Closure;
use GuzzleHttp\Psr7\CachingStream;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\StreamWrapper;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Filesystem\Filesystem as FilesystemContract;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Filesystem\FilesystemPoolProxy;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Image\ImageException;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class FilesystemPoolProxyTest extends TestCase
{
    private string $tempDir;

    private Filesystem $driver;

    private LocalFilesystemAdapter $adapter;

    private PoolManager $pools;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('FilesystemPoolProxy');
        $filesystem = new Filesystem(new LocalFilesystemAdapter(dirname($this->tempDir)));
        $filesystem->deleteDirectory(basename($this->tempDir));
        $this->adapter = new LocalFilesystemAdapter($this->tempDir);
        $this->driver = new Filesystem($this->adapter);
        $this->pools = new PoolManager;
    }

    protected function tearDownInCoroutine(): void
    {
        $this->pools->purgeAll();
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter(dirname($this->tempDir)));
        $filesystem->deleteDirectory(basename($this->tempDir));

        parent::tearDown();
    }

    public function testSynchronousOperationsUseAndReleaseAWholeDriver(): void
    {
        $creations = 0;
        $proxy = $this->proxy(function () use (&$creations): FilesystemAdapter {
            ++$creations;

            return $this->filesystem();
        });

        $this->assertTrue($proxy->put('file.txt', 'contents'));
        $this->assertTrue($proxy->exists('file.txt'));
        $this->assertSame('contents', $proxy->get('file.txt'));
        $this->assertSame(1, $creations);
        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedCount());
        $this->assertSame(1, $this->pools->get('filesystem:driver')->getIdleCount());
    }

    public function testJsonReturnsScalarDataAndReleasesTheDriver(): void
    {
        $this->driver->write('value.json', '"value"');
        $proxy = $this->proxy(fn (): FilesystemAdapter => $this->filesystem());

        $this->assertSame('value', $proxy->json('value.json'));
        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedCount());
    }

    public function testImageDefersAndBalancesTheWholeDriverLease(): void
    {
        $this->driver->write('photo.jpg', 'image bytes');
        $creations = 0;
        $releaseCalls = 0;
        $proxy = $this->proxy(
            function () use (&$creations): FilesystemAdapter {
                ++$creations;

                return $this->filesystem();
            },
            function (object $filesystem) use (&$releaseCalls): void {
                ++$releaseCalls;
            },
        );

        $image = $proxy->image('photo.jpg');

        $this->assertSame(0, $creations);
        $this->assertFalse($this->pools->has('filesystem:driver'));
        $this->assertSame('image bytes', $image->toBytes());
        $this->assertSame(1, $creations);
        $this->assertSame(1, $releaseCalls);
        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedCount());

        $this->assertSame('image bytes', $image->toBytes());
        $this->assertSame(1, $releaseCalls);
    }

    public function testMissingImageReleasesTheWholeDriverLease(): void
    {
        $releaseCalls = 0;
        $proxy = $this->proxy(
            fn (): FilesystemAdapter => $this->filesystem(),
            function (object $filesystem) use (&$releaseCalls): void {
                ++$releaseCalls;
            },
        );
        $image = $proxy->image('missing.jpg');

        try {
            $image->toBytes();
            $this->fail('Expected the missing image read to fail.');
        } catch (ImageException $exception) {
            $this->assertSame(
                'Unable to read image from path [missing.jpg].',
                $exception->getMessage(),
            );
        }

        $this->assertSame(1, $releaseCalls);
        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedCount());
    }

    public function testAssertEmptyReturnsTheProxyAndReleasesTheDriver(): void
    {
        $proxy = $this->proxy(fn (): FilesystemAdapter => $this->filesystem());

        $this->assertSame($proxy, $proxy->assertEmpty());
        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedCount());
    }

    public function testSynchronousFlysystemMethodsAndConditionableUseTheProxyBoundary(): void
    {
        $proxy = $this->proxy(fn (): FilesystemAdapter => $this->filesystem());

        $proxy->write('raw.txt', 'raw contents');
        $this->assertTrue($proxy->has('raw.txt'));
        $this->assertSame('raw contents', $proxy->read('raw.txt'));
        $this->assertSame(12, $proxy->fileSize('raw.txt'));
        $this->assertSame('public', $proxy->visibility('raw.txt'));
        $proxy->createDirectory('raw-directory');

        $this->assertTrue($this->driver->directoryExists('raw-directory'));
        $this->assertSame($proxy, $proxy->when(true, function (FilesystemPoolProxy $candidate) use ($proxy): void {
            $this->assertSame($proxy, $candidate);
            $this->assertSame($proxy, $candidate->unless(false, static fn (): null => null));
        }));
        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedCount());
    }

    public function testEveryCallbackSlotIsWrittenOnEveryBorrowAcrossSharedProxies(): void
    {
        $inner = null;
        $first = $this->proxy(function () use (&$inner): InspectableFilesystemAdapter {
            return $inner = $this->inspectableFilesystem();
        });
        $second = $this->proxy(fn (): InspectableFilesystemAdapter => $this->inspectableFilesystem());
        $first->serveUsing(static fn (): Response => new Response('served'));
        $first->buildTemporaryUrlsUsing(static fn (): string => 'first-url');
        $first->buildTemporaryUploadUrlsUsing(static fn (): array => ['url' => 'first-upload']);

        $this->assertFalse($first->exists('file.txt'));
        $this->assertInstanceOf(InspectableFilesystemAdapter::class, $inner);
        $this->assertTrue($inner->hasServeCallback());
        $this->assertSame('first-url', $first->temporaryUrl('file.txt', now()->addHour()));
        $this->assertSame(
            ['url' => 'first-upload'],
            $first->temporaryUploadUrl('file.txt', now()->addHour()),
        );

        $this->assertFalse($second->exists('file.txt'));
        $this->assertFalse($inner->hasServeCallback());
        $this->assertFalse($second->providesTemporaryUrls());
        $this->assertFalse($second->providesTemporaryUploadUrls());
    }

    public function testCrossDiskTransfersReleaseTheOnlyDriverBeforeWriting(): void
    {
        $proxy = new FilesystemPoolProxy(
            new PoolDefinition('filesystem:transfer', 'custom', 'transfer', PoolOptions::fromArray([
                'max_objects' => 1,
                'wait_timeout' => 0.02,
            ])),
            fn (): FilesystemAdapter => $this->filesystem(),
            $this->pools,
            ['driver' => 'custom'],
        );
        $this->driver->write('file.txt', 'contents');

        $this->assertTrue($proxy->copyToDisk($proxy, 'file.txt', 'copy.txt'));
        $this->assertSame('contents', $this->driver->read('copy.txt'));
        $this->assertTrue($proxy->moveToDisk($proxy, 'file.txt', 'moved.txt'));
        $this->assertSame('contents', $this->driver->read('moved.txt'));
        $this->assertFalse($this->driver->fileExists('file.txt'));
        $this->assertSame(0, $this->pools->get('filesystem:transfer')->getBorrowedCount());
    }

    public function testReadStreamKeepsTheWholeDriverBorrowedUntilClose(): void
    {
        $this->driver->write('file.txt', 'streamed');
        $releaseCalls = 0;
        $proxy = $this->proxy(
            fn (): FilesystemAdapter => $this->filesystem(),
            function (object $filesystem) use (&$releaseCalls): void {
                ++$releaseCalls;
            },
        );

        $stream = $proxy->readStream('file.txt');
        $this->assertIsResource($stream);
        $this->assertSame(1, $this->pools->get('filesystem:driver')->getBorrowedCount());
        $this->assertSame(0, $releaseCalls);
        $this->assertSame('streamed', stream_get_contents($stream));

        fclose($stream);

        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedCount());
        $this->assertSame(1, $releaseCalls);
    }

    #[DataProvider('bufferedReadProvider')]
    public function testBufferedReadsReleaseTheWholeDriverImmediately(string $uri, ?int $start, ?int $end, string $expected): void
    {
        $driver = m::mock(FilesystemOperator::class);
        $driver->shouldReceive('readStream')->once()->with('file.txt')->andReturnUsing(static function () use ($uri): mixed {
            $stream = fopen($uri, 'w+b');
            fwrite($stream, '0123456789');
            rewind($stream);

            return $stream;
        });
        $proxy = $this->proxy(fn (): FilesystemAdapter => new FilesystemAdapter($driver, $this->adapter));
        $stream = $start === null
            ? $proxy->readStream('file.txt')
            : $proxy->readStreamRange('file.txt', $start, $end);

        try {
            $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedCount());
            $this->assertSame(1, $this->pools->get('filesystem:driver')->getIdleCount());
            $this->assertSame(0, ftell($stream));
            $this->assertSame(strlen($expected), fstat($stream)['size']);
            $this->assertSame($expected, stream_get_contents($stream));
            rewind($stream);
            $this->assertSame($expected, stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    /**
     * Provide buffered streams and byte ranges.
     *
     * @return array<string, array{string, ?int, ?int, string}>
     */
    public static function bufferedReadProvider(): array
    {
        return [
            'temporary stream' => ['php://temp', null, null, '0123456789'],
            'memory stream' => ['php://memory', null, null, '0123456789'],
            'range spilled to disk' => ['php://temp/maxmemory:1', 3, 5, '345'],
        ];
    }

    public function testCachedLiveRangeKeepsTheWholeDriverBorrowedUntilClose(): void
    {
        $reads = 0;
        $source = new PumpStream(static function (int $length) use (&$reads): string|false {
            return ++$reads === 1 ? 'remote bytes' : false;
        });
        $range = StreamWrapper::getResource(new LimitStream(new CachingStream($source), 3));
        $driver = m::mock(FilesystemOperator::class);
        $driver->shouldReceive('readStream')->once()->with('file.txt')->andReturn($range);
        $proxy = $this->proxy(fn (): FilesystemAdapter => new FilesystemAdapter($driver, $this->adapter));
        $stream = $proxy->readStream('file.txt');

        try {
            $this->assertSame(0, $reads);
            $this->assertSame(1, $this->pools->get('filesystem:driver')->getBorrowedCount());
            $this->assertSame('rem', stream_get_contents($stream));
            $this->assertSame(1, $reads);
        } finally {
            fclose($stream);
        }

        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedCount());
    }

    public function testOperatorReadsPropagateFailuresAndReleaseTheWholeDriver(): void
    {
        $proxy = $this->proxy(fn (): FilesystemAdapter => $this->filesystem());
        $operator = $proxy->getOperator();
        $operator->write('file.txt', 'contents');
        $stream = $operator->readStream('file.txt');

        try {
            $this->assertSame('contents', stream_get_contents($stream));
            $this->assertSame(1, $this->pools->get('filesystem:driver')->getBorrowedCount());
        } finally {
            fclose($stream);
        }

        try {
            $operator->readStream('missing.txt');
            $this->fail('Expected a raw read failure even though the disk does not throw.');
        } catch (UnableToReadFile $exception) {
            $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedCount());
        }
    }

    public function testBoundedReadStreamKeepsTheWholeDriverBorrowedUntilClose(): void
    {
        $this->driver->write('file.txt', '0123456789');
        $releaseCalls = 0;
        $proxy = $this->proxy(
            fn (): FilesystemAdapter => $this->filesystem(),
            function (object $filesystem) use (&$releaseCalls): void {
                ++$releaseCalls;
            },
        );

        $stream = $proxy->readStreamRange('file.txt', 3, 5);

        $this->assertIsResource($stream);
        $this->assertSame(1, $this->pools->get('filesystem:driver')->getBorrowedCount());
        $this->assertSame(0, $releaseCalls);
        $this->assertSame('345', stream_get_contents($stream));

        fclose($stream);

        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedCount());
        $this->assertSame(1, $releaseCalls);
    }

    public function testBorrowScopedAccessorsExposeOnlyTheCurrentDriverBorrow(): void
    {
        $proxy = $this->proxy(fn (): FilesystemAdapter => $this->filesystem());

        $driver = $proxy->withDriver(function (object $driver): object {
            $this->assertSame(1, $this->pools->get('filesystem:driver')->getBorrowedCount());

            return $driver;
        });
        $adapter = $proxy->withAdapter(fn (object $adapter): object => $adapter);

        $this->assertSame($this->driver, $driver);
        $this->assertSame($this->adapter, $adapter);
        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedCount());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('does not support [getClient] access');
        $proxy->withClient(static fn (object $client): object => $client);
    }

    #[DataProvider('rejectedInternalProvider')]
    public function testBorrowedInternalsCannotEscape(string $method): void
    {
        $proxy = $this->proxy(fn (): FilesystemAdapter => $this->filesystem());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Pooled disks do not expose borrowed internals.');

        $proxy->{$method}();
    }

    public static function rejectedInternalProvider(): array
    {
        return [
            'driver' => ['getDriver'],
            'adapter' => ['getAdapter'],
            'client' => ['getClient'],
        ];
    }

    public function testUnknownMethodsAreRejectedInsteadOfDynamicallyForwarded(): void
    {
        $proxy = $this->proxy(fn (): FilesystemAdapter => $this->filesystem());

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIsOrContains('an unmapped call could return a lazy result');

        $proxy->listContents('', true);
    }

    public function testContractOnlyFilesystemPoolsWhenCallbacksAreUnset(): void
    {
        $filesystem = m::mock(FilesystemContract::class);
        $filesystem->shouldReceive('exists')->once()->with('file.txt')->andReturnTrue();
        $proxy = $this->proxy(static fn (): FilesystemContract => $filesystem);

        $this->assertTrue($proxy->exists('file.txt'));
        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedCount());
        $this->assertSame(1, $this->pools->get('filesystem:driver')->getIdleCount());
    }

    public function testSettingCallbacksOnAContractOnlyFilesystemFailsAndDiscardsIt(): void
    {
        $filesystem = m::mock(FilesystemContract::class);
        $proxy = $this->proxy(static fn (): FilesystemContract => $filesystem);
        $proxy->buildTemporaryUrlsUsing(static fn (): string => 'url');

        try {
            $proxy->exists('file.txt');
            $this->fail('Expected the callback capability check to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot receive serve or temporary URL callbacks', $exception->getMessage());
            $this->assertStringContainsString($filesystem::class, $exception->getMessage());
        }

        $this->assertSame(0, $this->pools->get('filesystem:driver')->getManagedCount());
    }

    public function testResponseUsesShortBorrowsAndClosesTheStreamLease(): void
    {
        $this->driver->write('file.txt', '0123456789');
        $request = Request::create('/file.txt', 'GET', server: ['HTTP_RANGE' => 'bytes=2-4']);
        RequestContext::set($request);
        $releaseCalls = 0;
        $proxy = $this->proxy(
            fn (): FilesystemAdapter => $this->filesystem(),
            function (object $filesystem) use (&$releaseCalls): void {
                ++$releaseCalls;
            },
        );

        $result = $proxy->response('file.txt');

        $this->assertInstanceOf(IterableStreamedResponse::class, $result);
        $this->assertSame(206, $result->getStatusCode());
        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedCount());
        $this->assertSame(2, $releaseCalls);

        $content = '';
        $this->assertTrue($result->streamTo(
            static function (string $chunk) use (&$content): bool {
                $content .= $chunk;

                return true;
            }
        ));

        $this->assertSame('234', $content);
        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedCount());
        $this->assertSame(3, $releaseCalls);
    }

    public function testConfigDefinitionAndInvalidationSurfaces(): void
    {
        $creations = 0;
        $proxy = $this->proxy(function () use (&$creations): FilesystemAdapter {
            ++$creations;

            return $this->filesystem();
        });

        $this->assertFalse($proxy->exists('missing.txt'));
        $this->assertSame(['driver' => 'custom'], $proxy->getConfig());
        $this->assertEquals($this->definition(), $proxy->getDefinition());
        $this->assertSame('filesystem:driver', $proxy->getPoolName());
        $this->assertTrue($proxy->invalidatePool());
        $this->assertFalse($proxy->invalidatePool());
        $this->assertFalse($proxy->exists('missing.txt'));
        $this->assertSame(2, $creations);
    }

    public function testReleaseCancellationSupersedesABorrowedAccessorFailure(): void
    {
        $operationFailure = new RuntimeException('operation failed');
        $releaseCancellation = new CanceledException('release canceled');
        $proxy = $this->proxy(
            fn (): FilesystemAdapter => $this->filesystem(),
            static function () use ($releaseCancellation): never {
                throw $releaseCancellation;
            },
        );

        try {
            $proxy->withDriver(static function () use ($operationFailure): never {
                throw $operationFailure;
            });
            $this->fail('Expected release cancellation to propagate.');
        } catch (Throwable $exception) {
            $this->assertSame($releaseCancellation, $exception);
        }

        $this->assertSame(0, $this->pools->get('filesystem:driver')->getManagedCount());
    }

    private function definition(): PoolDefinition
    {
        return new PoolDefinition(
            'filesystem:driver',
            'custom',
            'auto:driver',
            PoolOptions::fromArray([
                'max_lifetime' => null,
                'pool_idle_timeout' => null,
            ]),
        );
    }

    private function proxy(Closure $createCallback, ?Closure $releaseCallback = null): FilesystemPoolProxy
    {
        return new FilesystemPoolProxy(
            $this->definition(),
            $createCallback,
            $this->pools,
            ['driver' => 'custom'],
            $releaseCallback,
        );
    }

    private function filesystem(): FilesystemAdapter
    {
        return new FilesystemAdapter($this->driver, $this->adapter, ['root' => $this->tempDir]);
    }

    private function inspectableFilesystem(): InspectableFilesystemAdapter
    {
        return new InspectableFilesystemAdapter($this->driver, $this->adapter, ['root' => $this->tempDir]);
    }
}

class InspectableFilesystemAdapter extends FilesystemAdapter
{
    /**
     * Determine if a serve callback is currently configured.
     */
    public function hasServeCallback(): bool
    {
        return $this->serveCallback !== null;
    }
}
