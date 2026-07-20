<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Filesystem\LeasedStream;
use Hypervel\ObjectPool\Lease;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\ObjectPool\SimpleObjectPool;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use RuntimeException;
use stdClass;

class LeasedStreamTest extends TestCase
{
    public function testReadEofAndRewindDoNotReleaseUntilClose(): void
    {
        [$pool, $lease, $inner] = $this->leaseWithStream('contents');
        $stream = LeasedStream::wrap($inner, $lease);

        $this->assertSame('contents', stream_get_contents($stream));
        $this->assertTrue(feof($stream));
        $this->assertSame(1, $pool->getBorrowedObjectNumber());

        rewind($stream);
        $this->assertSame('contents', stream_get_contents($stream));
        $this->assertSame(1, $pool->getBorrowedObjectNumber());

        fclose($stream);
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
        $pool->close();
    }

    public function testExplicitCloseAndStreamResourceDestructionReleaseExactlyOnce(): void
    {
        $releaseCount = 0;
        [$pool, $lease, $inner] = $this->leaseWithStream(
            'contents',
            function () use (&$releaseCount): void {
                ++$releaseCount;
            },
        );
        $stream = LeasedStream::wrap($inner, $lease);

        fclose($stream);
        unset($stream);
        gc_collect_cycles();

        $this->assertSame(1, $releaseCount);
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $pool->close();
    }

    public function testAbandonedWrapperClosesInnerStreamAndReleasesLease(): void
    {
        [$pool, $lease, $inner] = $this->leaseWithStream('contents');
        $stream = LeasedStream::wrap($inner, $lease);

        unset($stream);
        gc_collect_cycles();

        $this->assertFalse(is_resource($inner));
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
        $pool->close();
    }

    public function testSeekTellAndStatForwardToTheInnerStream(): void
    {
        [$pool, $lease, $inner] = $this->leaseWithStream('0123456789');
        $stream = LeasedStream::wrap($inner, $lease);

        $this->assertSame(0, ftell($stream));
        $this->assertSame(0, fseek($stream, 4));
        $this->assertSame(4, ftell($stream));
        $this->assertSame('45', fread($stream, 2));
        $this->assertSame(10, fstat($stream)['size']);

        fclose($stream);
        $pool->close();
    }

    public function testStreamCastKeepsStreamSelectWorking(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        [$inner, $writer] = $sockets;
        $pool = $this->pool();
        $lease = new Lease($pool, $pool->get());
        $stream = LeasedStream::wrap($inner, $lease);
        fwrite($writer, 'ready');
        $read = [$stream];
        $write = null;
        $except = null;

        $this->assertSame(1, stream_select($read, $write, $except, 0, 100_000));
        $this->assertSame('ready', fread($stream, 5));

        fclose($writer);
        fclose($stream);
        $pool->close();
    }

    public function testSupportedStreamOptionsForwardAndUnsupportedOptionsFail(): void
    {
        if (! in_array(RecordingStreamWrapper::PROTOCOL, stream_get_wrappers(), true)) {
            $this->assertTrue(stream_wrapper_register(RecordingStreamWrapper::PROTOCOL, RecordingStreamWrapper::class));
        }

        RecordingStreamWrapper::$options = [];
        $inner = fopen(RecordingStreamWrapper::PROTOCOL . '://stream', 'r+');
        $this->assertIsResource($inner);
        $pool = $this->pool();
        $stream = LeasedStream::wrap($inner, new Lease($pool, $pool->get()));

        $this->assertTrue(stream_set_blocking($stream, false));
        $this->assertTrue(stream_set_timeout($stream, 1, 500_000));
        $this->assertSame(0, stream_set_write_buffer($stream, 8192));
        $this->assertSame(0, stream_set_write_buffer($stream, 0));
        $this->assertSame(-1, stream_set_read_buffer($stream, 0));
        $this->assertSame([
            [STREAM_OPTION_BLOCKING, 0, null],
            [STREAM_OPTION_READ_TIMEOUT, 1, 500_000],
            [STREAM_OPTION_WRITE_BUFFER, STREAM_BUFFER_FULL, 8192],
            [STREAM_OPTION_WRITE_BUFFER, STREAM_BUFFER_NONE, 8192],
        ], RecordingStreamWrapper::$options);

        fclose($stream);
        $pool->close();
    }

    public function testInvalidResourceIsRejectedWithoutTakingLeaseOwnership(): void
    {
        $pool = $this->pool();
        $lease = new Lease($pool, $pool->get());

        try {
            LeasedStream::wrap('not-a-resource', $lease);
            $this->fail('Expected the invalid resource to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('LeasedStream::wrap() expects an open stream resource.', $exception->getMessage());
        }

        $this->assertSame(1, $pool->getBorrowedObjectNumber());
        $lease->release();
        $pool->close();
    }

    public function testReleaseFailureDuringCloseIsReportedAndSwallowed(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $failure = new RuntimeException('release cleanup failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($failure);
        $container->instance(ExceptionHandler::class, $handler);
        $pool = new SimpleObjectPool(
            static fn (): object => new stdClass,
            PoolOptions::fromArray([]),
        );
        $lease = new Lease($pool, $pool->get(), function () use ($failure): never {
            throw $failure;
        });
        $inner = fopen('php://temp', 'r+');
        $this->assertIsResource($inner);
        $stream = LeasedStream::wrap($inner, $lease);

        fclose($stream);

        $this->assertSame(0, $pool->getCurrentObjectNumber());
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $pool->close();
    }

    public function testProtocolCollisionClosesResourceAndFinalizesLeaseTransactionally(): void
    {
        $result = $this->runFailureProbe(<<<'PHP'
stream_wrapper_register(LeasedStream::PROTOCOL, ForeignLeasedStreamWrapper::class);
PHP);

        $this->assertTrue($result['resource_closed']);
        $this->assertSame(0, $result['borrowed']);
        $this->assertSame(1, $result['idle']);
        $this->assertStringContainsString('already registered by other code', $result['message']);
    }

    public function testRegistrationFailureClosesResourceAndFinalizesLeaseTransactionally(): void
    {
        $result = $this->runFailureProbe('', <<<'PHP'
namespace Hypervel\Filesystem {
    function stream_wrapper_register(string $protocol, string $class, int $flags = 0): bool
    {
        return false;
    }
}
PHP);

        $this->assertTrue($result['resource_closed']);
        $this->assertSame(0, $result['borrowed']);
        $this->assertSame(1, $result['idle']);
        $this->assertStringContainsString('Unable to register', $result['message']);
    }

    public function testOpenFailureClosesResourceAndFinalizesLeaseTransactionally(): void
    {
        $result = $this->runFailureProbe('', <<<'PHP'
namespace Hypervel\Filesystem {
    function fopen(string $filename, string $mode, bool $useIncludePath = false, mixed $context = null): false
    {
        return false;
    }
}
PHP);

        $this->assertTrue($result['resource_closed']);
        $this->assertSame(0, $result['borrowed']);
        $this->assertSame(1, $result['idle']);
        $this->assertStringContainsString('Unable to open', $result['message']);
    }

    /**
     * @return array{resource_closed: bool, borrowed: int, idle: int, message: string}
     */
    private function runFailureProbe(string $setup, string $namespaceFunctions = ''): array
    {
        $autoload = var_export(realpath(__DIR__ . '/../../vendor/autoload.php'), true);
        $code = $namespaceFunctions . <<<'PHP'

namespace {
    require __AUTOLOAD__;

    use Hypervel\Filesystem\LeasedStream;
    use Hypervel\ObjectPool\Lease;
    use Hypervel\ObjectPool\PoolOptions;
    use Hypervel\ObjectPool\SimpleObjectPool;

    class ForeignLeasedStreamWrapper
    {
        public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
        {
            return true;
        }
    }

    $pool = new SimpleObjectPool(
        static fn (): object => new stdClass,
        PoolOptions::fromArray([]),
    );
    $lease = new Lease($pool, $pool->get());
    $resource = \fopen('php://temp', 'r+');
    __SETUP__
    $message = '';

    try {
        LeasedStream::wrap($resource, $lease);
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
    }

    echo json_encode([
        'resource_closed' => ! is_resource($resource),
        'borrowed' => $pool->getBorrowedObjectNumber(),
        'idle' => $pool->getObjectNumberInPool(),
        'message' => $message,
    ], JSON_THROW_ON_ERROR);
}
PHP;
        $code = str_replace(['__AUTOLOAD__', '__SETUP__'], [$autoload, $setup], $code);
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $errors);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($result);

        return $result;
    }

    /**
     * @return array{0: SimpleObjectPool, 1: Lease, 2: resource}
     */
    private function leaseWithStream(string $contents, ?Closure $releaseCallback = null): array
    {
        $pool = $this->pool();
        $lease = new Lease($pool, $pool->get(), $releaseCallback);
        $inner = fopen('php://temp', 'r+');
        $this->assertIsResource($inner);
        fwrite($inner, $contents);
        rewind($inner);

        return [$pool, $lease, $inner];
    }

    private function pool(): SimpleObjectPool
    {
        return new SimpleObjectPool(
            static fn (): object => new stdClass,
            PoolOptions::fromArray([]),
        );
    }
}

class RecordingStreamWrapper
{
    public const PROTOCOL = 'hypervel-leased-options-inner';

    /** @var resource */
    public $context;

    /** @var list<array{int, int, ?int}> */
    public static array $options = [];

    /**
     * Open the recording stream.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    /**
     * Record a stream option forwarded to this wrapper.
     */
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        static::$options[] = [$option, $arg1, $arg2];

        return true;
    }
}
