<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use BadMethodCallException;
use Closure;
use Hypervel\Context\RequestContext;
use Hypervel\Context\ResponseContext;
use Hypervel\Contracts\Filesystem\Filesystem as FilesystemContract;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Filesystem\FilesystemPoolProxy;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\FakeWritableConnection;
use Hypervel\Testing\ParallelTesting;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

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
        $this->adapter = new LocalFilesystemAdapter($this->tempDir);
        $this->driver = new Filesystem($this->adapter);
        $this->pools = new PoolManager($this->app);
    }

    protected function tearDownInCoroutine(): void
    {
        $this->pools->flush();
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
        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedObjectNumber());
        $this->assertSame(1, $this->pools->get('filesystem:driver')->getObjectNumberInPool());
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
        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedObjectNumber());
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
        $this->assertSame(1, $this->pools->get('filesystem:driver')->getBorrowedObjectNumber());
        $this->assertSame(0, $releaseCalls);
        $this->assertSame('streamed', stream_get_contents($stream));

        fclose($stream);

        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedObjectNumber());
        $this->assertSame(1, $releaseCalls);
    }

    public function testBorrowScopedAccessorsExposeOnlyTheCurrentDriverBorrow(): void
    {
        $proxy = $this->proxy(fn (): FilesystemAdapter => $this->filesystem());

        $driver = $proxy->withDriver(function (object $driver): object {
            $this->assertSame(1, $this->pools->get('filesystem:driver')->getBorrowedObjectNumber());

            return $driver;
        });
        $adapter = $proxy->withAdapter(fn (object $adapter): object => $adapter);

        $this->assertSame($this->driver, $driver);
        $this->assertSame($this->adapter, $adapter);
        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedObjectNumber());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support [getClient] access');
        $proxy->withClient(static fn (object $client): object => $client);
    }

    #[DataProvider('rejectedInternalProvider')]
    public function testBorrowedInternalsCannotEscape(string $method): void
    {
        $proxy = $this->proxy(fn (): FilesystemAdapter => $this->filesystem());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pooled disks do not expose borrowed internals.');

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
        $this->expectExceptionMessage('an unmapped call could return a lazy result');

        $proxy->listContents('', true);
    }

    public function testContractOnlyFilesystemPoolsWhenCallbacksAreUnset(): void
    {
        $filesystem = m::mock(FilesystemContract::class);
        $filesystem->shouldReceive('exists')->once()->with('file.txt')->andReturnTrue();
        $proxy = $this->proxy(static fn (): FilesystemContract => $filesystem);

        $this->assertTrue($proxy->exists('file.txt'));
        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedObjectNumber());
        $this->assertSame(1, $this->pools->get('filesystem:driver')->getObjectNumberInPool());
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

        $this->assertSame(0, $this->pools->get('filesystem:driver')->getCurrentObjectNumber());
    }

    public function testResponseUsesShortBorrowsAndClosesTheStreamLease(): void
    {
        $this->driver->write('file.txt', '0123456789');
        $request = Request::create('/file.txt', 'GET', server: ['HTTP_RANGE' => 'bytes=2-4']);
        RequestContext::set($request);
        $writable = new FakeWritableConnection;
        $response = new Response;
        $response->setConnection($writable);
        ResponseContext::set($response);
        $releaseCalls = 0;
        $proxy = $this->proxy(
            fn (): FilesystemAdapter => $this->filesystem(),
            function (object $filesystem) use (&$releaseCalls): void {
                ++$releaseCalls;
            },
        );

        $result = $proxy->response('file.txt');

        $this->assertSame(206, $result->getStatusCode());
        $this->assertSame('234', $writable->written);
        $this->assertSame(0, $this->pools->get('filesystem:driver')->getBorrowedObjectNumber());
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

    private function definition(): PoolDefinition
    {
        return new PoolDefinition(
            'filesystem:driver',
            'custom',
            'auto:driver',
            PoolOptions::fromArray([
                'max_lifetime' => 0,
                'idle_ttl' => null,
            ]),
        );
    }

    private function proxy(Closure $resolver, ?Closure $releaseCallback = null): FilesystemPoolProxy
    {
        return new FilesystemPoolProxy(
            $this->definition(),
            $resolver,
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
