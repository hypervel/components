<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use BadMethodCallException;
use Closure;
use DateTimeImmutable;
use Hypervel\Container\Container;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Filesystem\ClientPooledFilesystem;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\ObjectPool\Contracts\Factory;
use Hypervel\ObjectPool\Contracts\ObjectPool as ObjectPoolContract;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Throwable;

class ClientPooledFilesystemTest extends TestCase
{
    private string $tempDir;

    private Filesystem $driver;

    private LocalFilesystemAdapter $adapter;

    private PoolManager $pools;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('ClientPooledFilesystem');
        $filesystem = new Filesystem(new LocalFilesystemAdapter(dirname($this->tempDir)));
        $filesystem->deleteDirectory(basename($this->tempDir));
        $this->adapter = new LocalFilesystemAdapter($this->tempDir);
        $this->driver = new Filesystem($this->adapter);
        $this->pools = new PoolManager;
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

    public function testSynchronousOperationsBuildFreshStacksAroundOnePooledClient(): void
    {
        $clientCreations = 0;
        $stackCreations = 0;
        $disk = $this->disk($clientCreations, $stackCreations);

        $this->assertTrue($disk->put('file.txt', 'contents'));
        $this->assertTrue($disk->exists('file.txt'));
        $this->assertSame('contents', $disk->get('file.txt'));

        $this->assertSame(1, $clientCreations);
        $this->assertSame(3, $stackCreations);
        $this->assertSame(0, $this->pools->get('filesystem:test')->getBorrowedObjectNumber());
        $this->assertSame(1, $this->pools->get('filesystem:test')->getObjectNumberInPool());
    }

    public function testSynchronousFlysystemMethodsAndConditionableUseTheProxyBoundary(): void
    {
        $clientCreations = 0;
        $stackCreations = 0;
        $disk = $this->disk($clientCreations, $stackCreations);

        $disk->write('raw.txt', 'raw contents');
        $this->assertTrue($disk->has('raw.txt'));
        $this->assertSame('raw contents', $disk->read('raw.txt'));
        $this->assertSame(12, $disk->fileSize('raw.txt'));
        $this->assertSame('public', $disk->visibility('raw.txt'));
        $disk->createDirectory('raw-directory');

        $this->assertTrue($this->driver->directoryExists('raw-directory'));
        $this->assertSame($disk, $disk->when(true, function (ClientPooledFilesystem $proxy): void {
            $this->assertSame($proxy, $proxy->unless(
                false,
                fn (ClientPooledFilesystem $candidate) => $this->assertSame($proxy, $candidate),
            ));
        }));
        $this->assertSame(1, $clientCreations);
        $this->assertSame(6, $stackCreations);
        $this->assertSame(0, $this->pools->get('filesystem:test')->getBorrowedObjectNumber());
    }

    public function testCallbacksAreStoredPerDiskAndAppliedToEveryFreshStack(): void
    {
        $clientCreations = 0;
        $stackCreations = 0;
        $disk = $this->disk($clientCreations, $stackCreations);
        $expiration = new DateTimeImmutable('+1 hour');
        $disk->buildTemporaryUrlsUsing(
            static fn (string $path): string => 'temporary://' . $path,
        );
        $disk->buildTemporaryUploadUrlsUsing(
            static fn (string $path): array => ['url' => 'upload://' . $path, 'headers' => []],
        );

        $this->assertSame('temporary://first.txt', $disk->temporaryUrl('first.txt', $expiration));
        $this->assertSame('temporary://second.txt', $disk->temporaryUrl('second.txt', $expiration));
        $this->assertSame(
            ['url' => 'upload://third.txt', 'headers' => []],
            $disk->temporaryUploadUrl('third.txt', $expiration),
        );
        $this->assertSame(3, $stackCreations);

        $disk->buildTemporaryUrlsUsing(null);
        $disk->buildTemporaryUploadUrlsUsing(null);

        $this->assertFalse($disk->providesTemporaryUrls());
        $this->assertFalse($disk->providesTemporaryUploadUrls());
    }

    public function testServeCallbackRunsWithoutBorrowingAClient(): void
    {
        $clientCreations = 0;
        $stackCreations = 0;
        $disk = $this->disk($clientCreations, $stackCreations);
        $expected = new Response('custom');
        $disk->serveUsing(function (Request $request, string $path, array $headers) use ($expected): Response {
            $this->assertSame('/download', $request->getPathInfo());
            $this->assertSame('file.txt', $path);
            $this->assertSame(['X-Test' => 'yes'], $headers);

            return $expected;
        });

        $result = $disk->serve(
            Request::create('/download'),
            'file.txt',
            headers: ['X-Test' => 'yes'],
        );

        $this->assertSame($expected, $result);
        $this->assertSame(0, $clientCreations);
        $this->assertSame(0, $stackCreations);
    }

    public function testBorrowScopedAccessorsExposeOnlyTheCurrentBorrow(): void
    {
        $clientCreations = 0;
        $stackCreations = 0;
        $disk = $this->disk($clientCreations, $stackCreations);

        $client = $disk->withClient(function (object $client): object {
            $this->assertSame(1, $this->pools->get('filesystem:test')->getBorrowedObjectNumber());

            return $client;
        });
        $driver = $disk->withDriver(fn (object $driver): object => $driver);
        $adapter = $disk->withAdapter(fn (object $adapter): object => $adapter);

        $this->assertInstanceOf(ClientPooledFilesystemClient::class, $client);
        $this->assertSame($this->driver, $driver);
        $this->assertSame($this->adapter, $adapter);
        $this->assertSame(1, $clientCreations);
        $this->assertSame(3, $stackCreations);
        $this->assertSame(0, $this->pools->get('filesystem:test')->getBorrowedObjectNumber());
    }

    #[DataProvider('rejectedInternalProvider')]
    public function testBorrowedInternalsCannotEscape(string $method): void
    {
        $clientCreations = 0;
        $stackCreations = 0;
        $disk = $this->disk($clientCreations, $stackCreations);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pooled disks do not expose borrowed internals.');

        $disk->{$method}();
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
        $clientCreations = 0;
        $stackCreations = 0;
        $disk = $this->disk($clientCreations, $stackCreations);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('an unmapped call could return a lazy result');

        $disk->listContents('', true);
    }

    public function testReadStreamKeepsTheClientBorrowedUntilTheStreamCloses(): void
    {
        $this->driver->write('file.txt', 'streamed');
        $clientCreations = 0;
        $stackCreations = 0;
        $releaseCalls = 0;
        $disk = $this->disk(
            $clientCreations,
            $stackCreations,
            function (object $client) use (&$releaseCalls): void {
                ++$releaseCalls;
            },
        );

        $stream = $disk->readStream('file.txt');
        $this->assertIsResource($stream);
        $this->assertSame(1, $this->pools->get('filesystem:test')->getBorrowedObjectNumber());
        $this->assertSame(0, $releaseCalls);
        $this->assertSame('streamed', stream_get_contents($stream));

        fclose($stream);

        $this->assertSame(0, $this->pools->get('filesystem:test')->getBorrowedObjectNumber());
        $this->assertSame(1, $releaseCalls);
    }

    public function testNonResourceReadStreamResultReleasesImmediately(): void
    {
        $clientCreations = 0;
        $stackCreations = 0;
        $stack = m::mock(FilesystemAdapter::class);
        $stack->shouldReceive('readStream')->once()->with('missing.txt')->andReturnNull();
        $disk = $this->disk(
            $clientCreations,
            $stackCreations,
            stackFactory: static fn (object $client): FilesystemAdapter => $stack,
        );

        $this->assertNull($disk->readStream('missing.txt'));
        $this->assertSame(0, $this->pools->get('filesystem:test')->getBorrowedObjectNumber());
        $this->assertSame(1, $this->pools->get('filesystem:test')->getObjectNumberInPool());
    }

    public function testInvalidStackFactoryResultDiscardsTheBorrowedClient(): void
    {
        $clientCreations = 0;
        $stackCreations = 0;
        $disk = $this->disk(
            $clientCreations,
            $stackCreations,
            stackFactory: static fn (object $client): object => new stdClass,
        );

        try {
            $disk->exists('file.txt');
            $this->fail('Expected an invalid stack to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('stack factories must return', $exception->getMessage());
        }

        $this->assertSame(0, $this->pools->get('filesystem:test')->getCurrentObjectNumber());
        $this->assertSame(0, $this->pools->get('filesystem:test')->getBorrowedObjectNumber());
    }

    public function testDiscardFailureDoesNotMaskAStackFactoryFailure(): void
    {
        $container = Container::getInstance();
        $client = new ClientPooledFilesystemClient;
        $stackFailure = new RuntimeException('stack failed');
        $discardFailure = new RuntimeException('discard failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($discardFailure);
        $container->instance(ExceptionHandler::class, $handler);

        $pool = m::mock(ObjectPoolContract::class);
        $pool->shouldReceive('get')->once()->andReturn($client);
        $pool->shouldReceive('discard')->once()->with($client)->andThrow($discardFailure);
        $factory = m::mock(Factory::class);
        $factory->shouldReceive('getOrCreate')->once()->andReturn($pool);
        $disk = new ClientPooledFilesystem(
            $this->definition(),
            static fn (): object => $client,
            static function () use ($stackFailure): never {
                throw $stackFailure;
            },
            $factory,
            ['driver' => 's3'],
        );

        try {
            $disk->exists('file.txt');
            $this->fail('Expected stack construction to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($stackFailure, $exception);
        }

        gc_collect_cycles();
    }

    public function testInvalidatePoolMakesTheNextOperationCreateAFreshClient(): void
    {
        $this->driver->write('file.txt', 'contents');
        $clientCreations = 0;
        $stackCreations = 0;
        $disk = $this->disk($clientCreations, $stackCreations);

        $this->assertTrue($disk->exists('file.txt'));
        $this->assertTrue($disk->invalidatePool());
        $this->assertFalse($disk->invalidatePool());
        $this->assertTrue($disk->exists('file.txt'));

        $this->assertSame(2, $clientCreations);
        $this->assertSame('filesystem:test', $disk->getPoolName());
        $this->assertEquals($this->definition(), $disk->getDefinition());
        $this->assertSame(['driver' => 's3', 'nested' => ['value' => true]], $disk->getConfig());
    }

    public function testResponseUsesShortBorrowsAndReleasesTheStreamLease(): void
    {
        $this->driver->write('file.txt', '0123456789');
        $request = Request::create('/file.txt', 'GET', server: ['HTTP_RANGE' => 'bytes=4-6']);
        RequestContext::set($request);
        $clientCreations = 0;
        $stackCreations = 0;
        $releaseCalls = 0;
        $disk = $this->disk(
            $clientCreations,
            $stackCreations,
            function (object $client) use (&$releaseCalls): void {
                ++$releaseCalls;
            },
        );

        $result = $disk->response('file.txt');

        $this->assertInstanceOf(IterableStreamedResponse::class, $result);
        $this->assertSame(206, $result->getStatusCode());
        $this->assertSame(0, $this->pools->get('filesystem:test')->getBorrowedObjectNumber());
        $this->assertSame(1, $clientCreations);
        $this->assertSame(2, $stackCreations);
        $this->assertSame(2, $releaseCalls);

        $content = '';
        $this->assertTrue($result->streamTo(
            static function (string $chunk) use (&$content): bool {
                $content .= $chunk;

                return true;
            }
        ));

        $this->assertSame('456', $content);
        $this->assertSame(0, $this->pools->get('filesystem:test')->getBorrowedObjectNumber());
        $this->assertSame(1, $clientCreations);
        $this->assertSame(3, $stackCreations);
        $this->assertSame(3, $releaseCalls);
    }

    public function testOperationExceptionStaysPrimaryWhenReleaseCallbackAlsoThrows(): void
    {
        $container = Container::getInstance();
        $operationFailure = new RuntimeException('operation failed');
        $releaseFailure = new RuntimeException('release failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($releaseFailure);
        $container->instance(ExceptionHandler::class, $handler);
        $clientCreations = 0;
        $stackCreations = 0;
        $stack = m::mock(FilesystemAdapter::class);
        $stack->shouldReceive('get')->once()->with('file.txt')->andThrow($operationFailure);
        $disk = $this->disk(
            $clientCreations,
            $stackCreations,
            static function (object $client) use ($releaseFailure): never {
                throw $releaseFailure;
            },
            static fn (object $client): FilesystemAdapter => $stack,
        );

        try {
            $disk->get('file.txt');
            $this->fail('Expected the operation failure to propagate.');
        } catch (Throwable $exception) {
            $this->assertSame($operationFailure, $exception);
        }

        $this->assertSame(0, $this->pools->get('filesystem:test')->getCurrentObjectNumber());
    }

    private function definition(): PoolDefinition
    {
        return new PoolDefinition(
            'filesystem:test',
            's3',
            'auto:test',
            PoolOptions::fromArray([
                'max_lifetime' => 0,
                'idle_ttl' => null,
            ]),
        );
    }

    private function disk(
        int &$clientCreations,
        int &$stackCreations,
        ?Closure $releaseCallback = null,
        ?Closure $stackFactory = null,
    ): ClientPooledFilesystem {
        return new ClientPooledFilesystem(
            $this->definition(),
            function () use (&$clientCreations): object {
                ++$clientCreations;

                return new ClientPooledFilesystemClient;
            },
            function (object $client) use (&$stackCreations, $stackFactory): mixed {
                ++$stackCreations;

                return $stackFactory !== null
                    ? $stackFactory($client)
                    : new FilesystemAdapter($this->driver, $this->adapter, ['root' => $this->tempDir]);
            },
            $this->pools,
            ['driver' => 's3', 'nested' => ['value' => true]],
            $releaseCallback,
        );
    }
}

class ClientPooledFilesystemClient
{
}
