<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer;

use Closure;
use Hypervel\Contracts\Http\HasTrailers;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response as HypervelResponse;
use Hypervel\HttpServer\ResponseBridge;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use SplTempFileObject;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResponseBridgeTest extends TestCase
{
    protected string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = ParallelTesting::tempDir('ResponseBridgeTest');
        mkdir($this->tempDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDirectory);

        parent::tearDown();
    }

    public function testSendPlainResponse(): void
    {
        $response = new Response('Hello World', 200, ['X-Custom' => 'value']);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('status')->once()->with(200)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->atLeast()->once()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->with('Hello World')->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testSendStatusCode(): void
    {
        $response = new Response('Not Found', 404);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('status')->once()->with(404)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->with('Not Found')->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testSendHeaders(): void
    {
        $response = new Response('OK', 200, [
            'Content-Type' => 'application/json',
            'X-Request-Id' => 'abc-123',
            'X-Repeated' => ['one', 'two'],
        ]);
        $swooleResponse = $this->mockSwooleResponse();

        $sentHeaders = [];
        $swooleResponse->shouldReceive('status')->once()->with(200)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->andReturnUsing(function (string $name, string|array $value) use (&$sentHeaders): bool {
            $sentHeaders[$name] = $value;

            return true;
        });
        $swooleResponse->shouldReceive('end')->once()->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse);

        $this->assertSame('application/json', $sentHeaders['Content-Type'] ?? $sentHeaders['content-type'] ?? null);
        $this->assertSame('abc-123', $sentHeaders['X-Request-Id'] ?? null);
        $this->assertSame(['one', 'two'], $sentHeaders['X-Repeated'] ?? null);
    }

    public function testSendCookies(): void
    {
        $response = new Response('OK', 200);
        $response->headers->setCookie(Cookie::create('session', 'abc123')->withPath('/')->withSecure(true));
        $swooleResponse = $this->mockSwooleResponse();

        $sentCookies = [];
        $swooleResponse->shouldReceive('status')->once()->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturn(true);
        $swooleResponse->shouldReceive('cookie')->andReturnUsing(function (...$args) use (&$sentCookies) {
            $sentCookies[] = $args;
            return true;
        });
        $swooleResponse->shouldReceive('end')->once()->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse);

        $this->assertCount(1, $sentCookies);
        $this->assertSame('session', $sentCookies[0][0]);
        $this->assertSame('abc123', $sentCookies[0][1]);
    }

    public function testSendRawPartitionedCookie(): void
    {
        $response = new Response('OK');
        $response->headers->setCookie(
            Cookie::create('raw', 'a%2Fb')->withRaw()->withPartitioned()
        );
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldNotReceive('cookie');
        $swooleResponse->shouldReceive('rawcookie')->once()->with(
            'raw',
            'a%2Fb',
            0,
            '/',
            '',
            false,
            true,
            'lax',
            '',
            true,
        )->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testSendWithoutBodyForHeadRequest(): void
    {
        $response = new Response('This body should not be sent', 200, ['Content-Length' => '28']);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('status')->once()->with(200)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse, withBody: false);
    }

    public function testSendNonStreamedHypervelResponse(): void
    {
        $response = new HypervelResponse('Normal content', 200);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('status')->once()->with(200)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->with('Normal content')->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testSendBinaryFileResponse(): void
    {
        $path = $this->temporaryFile();
        $response = new BinaryFileResponse($path);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('status')->once()->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('sendfile')->once()->with($path, 0, 0)->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testPreparedBinaryRangeUsesBoundedNativeSendfileArgumentsForHttpOne(): void
    {
        $path = $this->temporaryFile();
        $response = new BinaryFileResponse($path);
        $response->prepare(Request::create('/', 'GET', server: ['HTTP_RANGE' => 'bytes=2-5']));
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('status')->once()->with(206)->andReturnTrue();
        $swooleResponse->shouldReceive('sendfile')->once()->with($path, 2, 4)->andReturnTrue();
        $swooleResponse->shouldNotReceive('write');
        $swooleResponse->shouldNotReceive('end');

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testHttpTwoBinaryResponseStreamsBoundedChunksWithoutConflictingFramingHeaders(): void
    {
        $path = $this->temporaryFile();
        $response = new BinaryFileResponse($path);
        $response->setChunkSize(4);
        $response->prepare(Request::create('/'));
        $response->headers->set('Transfer-Encoding', 'chunked');
        $swooleResponse = $this->mockSwooleResponse();
        $headers = [];
        $chunks = [];

        $swooleResponse->shouldReceive('header')->andReturnUsing(
            static function (string $name, string|array $value) use (&$headers): bool {
                $headers[strtolower($name)] = $value;

                return true;
            }
        );
        $swooleResponse->shouldReceive('sendfile')->never();
        $swooleResponse->shouldReceive('write')->times(4)->andReturnUsing(
            static function (string $chunk) use (&$chunks): bool {
                $chunks[] = $chunk;

                return true;
            }
        );
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse, protocol: 'HTTP/2');

        $this->assertSame('file contents', implode('', $chunks));
        $this->assertArrayNotHasKey('content-length', $headers);
        $this->assertArrayNotHasKey('transfer-encoding', $headers);
    }

    public function testHttpTwoBinaryRangeStreamsOnlyThePreparedBytes(): void
    {
        $path = $this->temporaryFile();
        $response = new BinaryFileResponse($path);
        $response->setChunkSize(2);
        $response->prepare(Request::create('/', 'GET', server: ['HTTP_RANGE' => 'bytes=5-8']));
        $swooleResponse = $this->mockSwooleResponse();
        $chunks = [];

        $swooleResponse->shouldReceive('sendfile')->never();
        $swooleResponse->shouldReceive('write')->twice()->andReturnUsing(
            static function (string $chunk) use (&$chunks): bool {
                $chunks[] = $chunk;

                return true;
            }
        );
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse, protocol: 'HTTP/2');

        $this->assertSame('cont', implode('', $chunks));
    }

    public function testTemporaryBinaryResponseUsesBoundedStreaming(): void
    {
        $file = new SplTempFileObject;
        $file->fwrite('temporary contents');
        $response = new BinaryFileResponse($file);
        $response->setChunkSize(4);
        $response->prepare(Request::create('/'));
        $swooleResponse = $this->mockSwooleResponse();
        $chunks = [];

        $swooleResponse->shouldReceive('sendfile')->never();
        $swooleResponse->shouldReceive('write')->andReturnUsing(
            static function (string $chunk) use (&$chunks): bool {
                $chunks[] = $chunk;

                return true;
            }
        );
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse);

        $this->assertSame('temporary contents', implode('', $chunks));
    }

    public function testDeleteAfterSendStreamsBeforeDeletingAndRemovesConflictingHeaders(): void
    {
        $path = $this->temporaryFile();
        $response = new BinaryFileResponse($path);
        $response->setChunkSize(4);
        $response->deleteFileAfterSend();
        $response->prepare(Request::create('/'));
        $swooleResponse = $this->mockSwooleResponse();
        $headers = [];
        $chunks = [];

        $swooleResponse->shouldReceive('header')->andReturnUsing(
            static function (string $name, string|array $value) use (&$headers): bool {
                $headers[strtolower($name)] = $value;

                return true;
            }
        );
        $swooleResponse->shouldReceive('sendfile')->never();
        $swooleResponse->shouldReceive('write')->andReturnUsing(
            static function (string $chunk) use (&$chunks): bool {
                $chunks[] = $chunk;

                return true;
            }
        );
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse);

        $this->assertSame('file contents', implode('', $chunks));
        $this->assertArrayNotHasKey('content-length', $headers);
        $this->assertArrayNotHasKey('transfer-encoding', $headers);
        $this->assertFileDoesNotExist($path);
    }

    public function testDeleteAfterSendPreservesWriteFailureAndStillEndsAndDeletes(): void
    {
        $path = $this->temporaryFile();
        $response = new BinaryFileResponse($path);
        $response->deleteFileAfterSend();
        $response->prepare(Request::create('/'));
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('write')->once()->andReturnFalse();
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnFalse();

        try {
            ResponseBridge::send($response, $swooleResponse);
            $this->fail('Expected the write failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to write the binary response.', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($path);
    }

    public function testDeleteAfterSendPreservesEarlyEofFailureAndStillDeletes(): void
    {
        $path = $this->temporaryFile();
        $response = new BinaryFileResponse($path);
        $response->deleteFileAfterSend();
        $response->prepare(Request::create('/', 'GET', server: ['HTTP_RANGE' => 'bytes=0-11']));
        file_put_contents($path, 'short');
        clearstatcache(true, $path);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('write')->once()->with('short')->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnTrue();

        try {
            ResponseBridge::send($response, $swooleResponse, protocol: 'HTTP/2');
            $this->fail('Expected the premature EOF failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The binary response file ended before the prepared range was complete.',
                $exception->getMessage(),
            );
        }

        $this->assertFileDoesNotExist($path);
    }

    public function testDeleteAfterSendPropagatesEndFailureAfterDeleting(): void
    {
        $path = $this->temporaryFile();
        $response = new BinaryFileResponse($path);
        $response->deleteFileAfterSend();
        $response->prepare(Request::create('/'));
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('write')->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnFalse();

        try {
            ResponseBridge::send($response, $swooleResponse);
            $this->fail('Expected the end failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to complete the response.', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($path);
    }

    public function testDeleteAfterSendPreservesStatusFailureAndStillEndsAndDeletes(): void
    {
        $path = $this->temporaryFile();
        $response = new BinaryFileResponse($path);
        $response->deleteFileAfterSend();
        $response->prepare(Request::create('/'));
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('status')->once()->andReturnFalse();
        $swooleResponse->shouldNotReceive('header');
        $swooleResponse->shouldNotReceive('write');
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnFalse();

        try {
            ResponseBridge::send($response, $swooleResponse);
            $this->fail('Expected the status failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to set the response status.', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($path);
    }

    public function testDeleteAfterSendPropagatesDeletionFailureAfterSuccessfulEmission(): void
    {
        $path = $this->temporaryFile();
        $response = new BinaryFileResponse($path);
        $response->deleteFileAfterSend();
        $response->prepare(Request::create('/'));
        $swooleResponse = $this->mockSwooleResponse();
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnTrue();
        $exception = null;

        try {
            ResponseBridgeWithFailingDeletion::send($response, $swooleResponse);
        } catch (RuntimeException $throwable) {
            $exception = $throwable;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame('Unable to delete the binary response file.', $exception->getMessage());
        $this->assertFileExists($path);
    }

    public function testHeadDeleteAfterSendSuppressesTheBodyAndStillDeletes(): void
    {
        $trust = new ReflectionProperty(BinaryFileResponse::class, 'trustXSendfileTypeHeader');
        $previousTrust = $trust->getValue();
        $path = $this->temporaryFile();

        try {
            BinaryFileResponse::trustXSendfileTypeHeader();
            $request = Request::create('/', 'HEAD');
            $response = new BinaryFileResponse($path);
            $response->deleteFileAfterSend();
            $response->prepare($request);
            $swooleResponse = $this->mockSwooleResponse();

            $swooleResponse->shouldNotReceive('sendfile');
            $swooleResponse->shouldNotReceive('write');
            $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnTrue();

            ResponseBridge::send($response, $swooleResponse, withBody: false, request: $request);

            $this->assertFileDoesNotExist($path);
        } finally {
            $trust->setValue(null, $previousTrust);
        }
    }

    #[DataProvider('binaryResponseStatusesWithoutBodies')]
    public function testDeleteAfterSendDeletesResponsesWithoutBodies(int $status): void
    {
        $path = $this->temporaryFile();
        $response = new BinaryFileResponse($path, $status);
        $response->deleteFileAfterSend();
        $response->prepare(Request::create('/'));
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldNotReceive('sendfile');
        $swooleResponse->shouldNotReceive('write');
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse);

        $this->assertFileDoesNotExist($path);
    }

    public static function binaryResponseStatusesWithoutBodies(): array
    {
        return [
            'informational' => [101],
            'empty' => [204],
            'unsuccessful' => [404],
        ];
    }

    public function testDelegatedXSendfilePreservesDeleteAfterSendSource(): void
    {
        $trust = new ReflectionProperty(BinaryFileResponse::class, 'trustXSendfileTypeHeader');
        $previousTrust = $trust->getValue();
        $path = $this->temporaryFile();

        try {
            BinaryFileResponse::trustXSendfileTypeHeader();
            $request = Request::create('/', 'GET', server: ['HTTP_X_SENDFILE_TYPE' => 'X-Sendfile']);
            $response = new BinaryFileResponse($path);
            $response->deleteFileAfterSend();
            $response->prepare($request);
            $swooleResponse = $this->mockSwooleResponse();

            $swooleResponse->shouldNotReceive('sendfile');
            $swooleResponse->shouldNotReceive('write');
            $swooleResponse->shouldReceive('header')->with('X-Sendfile', $path)->andReturnTrue();
            $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnTrue();

            ResponseBridge::send($response, $swooleResponse, request: $request);

            $this->assertFileExists($path);
        } finally {
            $trust->setValue(null, $previousTrust);
        }
    }

    public function testDelegatedXSendfileHeadPreservesDeleteAfterSendSource(): void
    {
        $trust = new ReflectionProperty(BinaryFileResponse::class, 'trustXSendfileTypeHeader');
        $previousTrust = $trust->getValue();
        $path = $this->temporaryFile();

        try {
            BinaryFileResponse::trustXSendfileTypeHeader();
            $request = Request::create('/', 'HEAD', server: [
                'HTTP_X_SENDFILE_TYPE' => 'X-Custom-Sendfile',
            ]);
            $response = new BinaryFileResponse($path);
            $response->deleteFileAfterSend();
            $response->prepare($request);
            $swooleResponse = $this->mockSwooleResponse();

            $swooleResponse->shouldNotReceive('sendfile');
            $swooleResponse->shouldNotReceive('write');
            $swooleResponse->shouldReceive('header')->with('X-Custom-Sendfile', $path)->andReturnTrue();
            $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnTrue();

            ResponseBridge::send($response, $swooleResponse, withBody: false, request: $request);

            $this->assertFileExists($path);
        } finally {
            $trust->setValue(null, $previousTrust);
        }
    }

    public function testSendStreamedResponse(): void
    {
        $chunks = [];
        $response = new StreamedResponse(function (): void {
            echo 'chunk1';
            echo 'chunk2';
        });

        $swooleResponse = $this->mockSwooleResponse();
        $swooleResponse->shouldReceive('status')->once()->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('write')->andReturnUsing(function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;

            return true;
        });
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnFalse();

        ResponseBridge::send($response, $swooleResponse);

        $this->assertNotEmpty($chunks);
        $this->assertSame('chunk1chunk2', implode('', $chunks));
    }

    public function testIterableStreamedResponseStopsAfterAFailedWrite(): void
    {
        $produced = 0;
        $closed = false;
        $chunks = (function () use (&$produced, &$closed): iterable {
            try {
                ++$produced;
                yield 'first';
                ++$produced;
                yield 'second';
            } finally {
                $closed = true;
            }
        })();
        $response = new IterableStreamedResponse($chunks);
        unset($chunks);
        $swooleResponse = $this->mockSwooleResponse();
        $swooleResponse->shouldReceive('status')->once()->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('write')->once()->with('first')->andReturnFalse();
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnFalse();

        ResponseBridge::send($response, $swooleResponse);

        $this->assertSame(1, $produced);
        $this->assertTrue($closed);
    }

    public function testIterableStreamedResponsePreservesWriteFailureAndStillEnds(): void
    {
        $closed = false;
        $chunks = (function () use (&$closed): iterable {
            try {
                yield 'first';
            } finally {
                $closed = true;
            }
        })();
        $response = new IterableStreamedResponse($chunks);
        unset($chunks);
        $swooleResponse = $this->mockSwooleResponse();
        $swooleResponse->shouldReceive('status')->once()->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('write')->once()->andThrow(new RuntimeException('write failed'));
        $swooleResponse->shouldReceive('end')->once()->andThrow(new RuntimeException('end failed'));

        try {
            ResponseBridge::send($response, $swooleResponse);
            $this->fail('Expected the write failure to propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('write failed', $exception->getMessage());
        }

        $this->assertTrue($closed);
    }

    public function testOrdinaryStreamedResponseStopsWritingAfterDisconnect(): void
    {
        $callbackCompleted = false;
        $response = new StreamedResponse(function () use (&$callbackCompleted): void {
            echo 'first';
            echo 'second';
            $callbackCompleted = true;
        });
        $swooleResponse = $this->mockSwooleResponse();
        $swooleResponse->shouldReceive('status')->once()->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('write')->once()->with('first')->andReturnFalse();
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnFalse();

        ResponseBridge::send($response, $swooleResponse);

        $this->assertTrue($callbackCompleted);
    }

    public function testOrdinaryStreamedResponsePreservesWriteFailureAndStillEnds(): void
    {
        $response = new StreamedResponse(static function (): void {
            echo 'first';
        });
        $swooleResponse = $this->mockSwooleResponse();
        $swooleResponse->shouldReceive('status')->once()->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('write')->once()->andThrow(new RuntimeException('write failed'));
        $swooleResponse->shouldReceive('end')->once()->andThrow(new RuntimeException('end failed'));

        try {
            ResponseBridge::send($response, $swooleResponse);
            $this->fail('Expected the write failure to propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('write failed', $exception->getMessage());
        }
    }

    public function testStreamedResponseRemovesConflictingHeaders(): void
    {
        $response = new StreamedResponse(function (): void {
            echo 'data';
        });
        $response->headers->set('Content-Length', '4');
        $response->headers->set('Transfer-Encoding', 'chunked');

        $swooleResponse = $this->mockSwooleResponse();
        $sentHeaders = [];
        $swooleResponse->shouldReceive('status')->once()->andReturnTrue();
        $swooleResponse->shouldReceive('header')->andReturnUsing(function (string $name, string|array $value) use (&$sentHeaders): bool {
            $sentHeaders[$name] = $value;

            return true;
        });
        $swooleResponse->shouldReceive('write')->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->andReturnFalse();

        ResponseBridge::send($response, $swooleResponse);

        $this->assertArrayNotHasKey('Content-Length', $sentHeaders);
        $this->assertArrayNotHasKey('Transfer-Encoding', $sentHeaders);
    }

    public function testStreamedResponseCleansUpOutputBufferOnException(): void
    {
        $response = new StreamedResponse(function (): void {
            echo 'partial';
            throw new RuntimeException('Stream error');
        });

        $swooleResponse = $this->mockSwooleResponse();
        $swooleResponse->shouldReceive('status')->once()->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('write')->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->andReturnFalse();

        $levelBefore = ob_get_level();

        try {
            ResponseBridge::send($response, $swooleResponse);
            $this->fail('Expected RuntimeException to propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('Stream error', $exception->getMessage());
        }

        $this->assertSame($levelBefore, ob_get_level());
    }

    public function testSendMultipleCookies(): void
    {
        $response = new Response('OK', 200);
        $response->headers->setCookie(Cookie::create('first', 'one'));
        $response->headers->setCookie(Cookie::create('second', 'two'));

        $swooleResponse = $this->mockSwooleResponse();
        $cookieNames = [];
        $swooleResponse->shouldReceive('status')->once()->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('cookie')->andReturnUsing(function (string $name) use (&$cookieNames): bool {
            $cookieNames[] = $name;

            return true;
        });
        $swooleResponse->shouldReceive('end')->once()->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse);

        $this->assertContains('first', $cookieNames);
        $this->assertContains('second', $cookieNames);
    }

    public function testSendEmptyBodyResponse(): void
    {
        $response = new Response('', 204);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('status')->once()->with(204)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->with('')->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testHeadResponseAnnouncesTrailersWithoutProducingContentOrFinalTrailers(): void
    {
        $producerCalls = 0;
        $trailerCalls = 0;
        $response = new ResponseBridgeTrailerIterableResponse(
            (function () use (&$producerCalls): iterable {
                ++$producerCalls;

                yield 'body';
            })(),
            ['X-Known'],
            function () use (&$trailerCalls): array {
                ++$trailerCalls;

                return ['x-known' => 'value'];
            },
        );
        $response->headers->set('Content-Length', '4');
        $swooleResponse = $this->mockSwooleResponse();
        $headers = [];

        $swooleResponse->shouldReceive('header')->andReturnUsing(function (string $name, string|array $value) use (&$headers): bool {
            $headers[strtolower($name)] = $value;

            return true;
        });
        $swooleResponse->shouldNotReceive('write');
        $swooleResponse->shouldNotReceive('trailer');
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnTrue();

        ResponseBridge::send($response, $swooleResponse, withBody: false);

        $this->assertSame(0, $producerCalls);
        $this->assertSame(0, $trailerCalls);
        $this->assertSame('x-known', $headers['trailer']);
        $this->assertSame('4', $headers['content-length']);
    }

    public function testBinaryFileResponseWithTrailersIsRejectedBeforeEmission(): void
    {
        $response = new ResponseBridgeTrailerBinaryFileResponse($this->temporaryFile());
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldNotReceive('status');
        $swooleResponse->shouldNotReceive('header');
        $swooleResponse->shouldNotReceive('sendfile');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Binary file responses cannot emit trailers.');

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testNormalTrailerResponseAnnouncesSendsAndFinalizesInOrder(): void
    {
        $operations = [];
        $response = new ResponseBridgeTrailerResponse(
            '',
            ['X-Known'],
            function () use (&$operations): array {
                $operations[] = 'evaluate-trailers';

                return ['X-Final' => 'value'];
            },
        );
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('status')->once()->andReturnUsing(function () use (&$operations): bool {
            $operations[] = 'status';

            return true;
        });
        $swooleResponse->shouldReceive('header')->andReturnUsing(function (string $name) use (&$operations): bool {
            if (strtolower($name) === 'trailer') {
                $operations[] = 'announce';
            }

            return true;
        });
        $swooleResponse->shouldReceive('trailer')->once()->with('x-final', 'value')->andReturnUsing(function () use (&$operations): bool {
            $operations[] = 'trailer';

            return true;
        });
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnUsing(function () use (&$operations): bool {
            $operations[] = 'end';

            return true;
        });

        ResponseBridge::send($response, $swooleResponse);

        $this->assertSame(['status', 'announce', 'evaluate-trailers', 'trailer', 'end'], $operations);
    }

    public function testTrailerAnnouncementMergesNormalizesAndDeduplicatesNames(): void
    {
        $response = new ResponseBridgeTrailerResponse('body', ['X-Known', 'x-new'], []);
        $response->headers->set('Trailer', 'X-Existing, x-known');
        $swooleResponse = $this->mockSwooleResponse();
        $announcement = null;

        $swooleResponse->shouldReceive('header')->andReturnUsing(function (string $name, string|array $value) use (&$announcement): bool {
            if (strtolower($name) === 'trailer') {
                $announcement = $value;
            }

            return true;
        });

        ResponseBridge::send($response, $swooleResponse);

        $this->assertSame('x-existing, x-known, x-new', $announcement);
    }

    #[DataProvider('invalidTrailerNames')]
    public function testInvalidAnnouncedTrailerNameFailsBeforeEmission(string $name, string $message): void
    {
        $response = new ResponseBridgeTrailerResponse('body', [$name], []);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldNotReceive('status');
        $swooleResponse->shouldNotReceive('header');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        ResponseBridge::send($response, $swooleResponse);
    }

    #[DataProvider('invalidTrailerNames')]
    public function testInvalidFinalTrailerNameFailsAtTrailerBoundary(string $name, string $message): void
    {
        $response = new ResponseBridgeTrailerResponse('body', [], [$name => 'value']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        ResponseBridge::send($response, $this->mockSwooleResponse());
    }

    public static function invalidTrailerNames(): array
    {
        return [
            'empty' => ['', 'Response trailer names must be valid HTTP field names.'],
            'whitespace' => ['x value', 'Response trailer names must be valid HTTP field names.'],
            'pseudo field' => [':status', 'Response trailer names must be valid HTTP field names.'],
            'framing field' => ['content-length', 'The response trailer name is forbidden.'],
            'connection field' => ['connection', 'The response trailer name is forbidden.'],
            'native length limit' => [str_repeat('a', 128), 'Response trailer names cannot exceed 127 bytes.'],
        ];
    }

    public function testFinalTrailerNamesMustBeUniqueAfterNormalization(): void
    {
        $response = new ResponseBridgeTrailerResponse('body', [], [
            'X-Value' => 'one',
            'x-value' => 'two',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Response trailer names must be unique after normalization.');

        ResponseBridge::send($response, $this->mockSwooleResponse());
    }

    #[DataProvider('invalidFinalTrailers')]
    public function testFinalTrailerNamesAndValuesMustBeStrings(array $trailers): void
    {
        $response = new ResponseBridgeTrailerResponse('body', [], $trailers);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Response trailer names and values must be strings.');

        ResponseBridge::send($response, $this->mockSwooleResponse());
    }

    public static function invalidFinalTrailers(): array
    {
        return [
            'numeric name' => [[0 => 'value']],
            'non-string value' => [['x-value' => 123]],
        ];
    }

    public function testFinalTrailerValuesCannotContainLineBreaks(): void
    {
        $response = new ResponseBridgeTrailerResponse('body', [], ['x-value' => "one\r\ntwo"]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Response trailer values cannot contain line breaks.');

        ResponseBridge::send($response, $this->mockSwooleResponse());
    }

    #[DataProvider('trailerChunkCases')]
    public function testTrailerIterableUsesOneChunkLookahead(
        array $chunks,
        array $expectedWrites,
        ?string $expectedEndContent
    ): void {
        $response = new ResponseBridgeTrailerIterableResponse(
            $chunks,
            ['X-Known'],
            ['X-Known' => 'known', 'X-Late' => 'late'],
        );

        [$writes, $endContent, $announcement, $trailers] = $this->sendTrailerStream($response);

        $this->assertSame($expectedWrites, $writes);
        $this->assertSame($expectedEndContent, $endContent);
        $this->assertSame('x-known', $announcement);
        $this->assertSame(['x-known' => 'known', 'x-late' => 'late'], $trailers);
    }

    #[DataProvider('trailerChunkCases')]
    public function testSymfonyTrailerStreamUsesOneChunkLookahead(
        array $chunks,
        array $expectedWrites,
        ?string $expectedEndContent
    ): void {
        $response = new ResponseBridgeTrailerStreamedResponse(
            function () use ($chunks): void {
                foreach ($chunks as $chunk) {
                    echo $chunk;
                }
            },
            ['X-Known'],
            ['X-Known' => 'known', 'X-Late' => 'late'],
        );

        [$writes, $endContent, $announcement, $trailers] = $this->sendTrailerStream($response);

        $this->assertSame($expectedWrites, $writes);
        $this->assertSame($expectedEndContent, $endContent);
        $this->assertSame('x-known', $announcement);
        $this->assertSame(['x-known' => 'known', 'x-late' => 'late'], $trailers);
    }

    public static function trailerChunkCases(): array
    {
        return [
            'zero' => [[], [], null],
            'one' => [['one'], [], 'one'],
            'multiple' => [['one', 'two', 'three'], ['one', 'two'], 'three'],
            'empty chunks ignored' => [['', 'one', '', 'two', ''], ['one'], 'two'],
        ];
    }

    public function testTrailerIterableFallsBackAfterCallbackReplacement(): void
    {
        $response = new ResponseBridgeTrailerIterableResponse([], ['X-Known'], ['X-Known' => 'known']);
        $response->setCallback(static function (): void {
            echo 'one';
            echo 'two';
        });

        [$writes, $endContent] = $this->sendTrailerStream($response);

        $this->assertSame(['one'], $writes);
        $this->assertSame('two', $endContent);
    }

    public function testTrailerIterableEmitsTrailersOnlyAfterProductionAndEndsWithFinalChunk(): void
    {
        $operations = [];
        $response = new ResponseBridgeTrailerIterableResponse(
            (function () use (&$operations): iterable {
                $operations[] = 'produce-one';
                yield 'one';
                $operations[] = 'produce-two';
                yield 'two';
                $operations[] = 'producer-complete';
            })(),
            ['x-status'],
            function () use (&$operations): array {
                $operations[] = 'evaluate-trailers';

                return ['x-status' => 'ok'];
            },
        );
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('write')->once()->with('one')->andReturnUsing(function () use (&$operations): bool {
            $operations[] = 'write-one';

            return true;
        });
        $swooleResponse->shouldReceive('trailer')->once()->andReturnUsing(function () use (&$operations): bool {
            $operations[] = 'trailer';

            return true;
        });
        $swooleResponse->shouldReceive('end')->once()->with('two')->andReturnUsing(function () use (&$operations): bool {
            $operations[] = 'end-two';

            return true;
        });

        ResponseBridge::send($response, $swooleResponse);

        $this->assertSame([
            'produce-one',
            'produce-two',
            'write-one',
            'producer-complete',
            'evaluate-trailers',
            'trailer',
            'end-two',
        ], $operations);
    }

    public function testTrailerIterableWriteFailureStopsProducerWithoutTrailersOrEnd(): void
    {
        $produced = 0;
        $closed = false;
        $response = new ResponseBridgeTrailerIterableResponse(
            (function () use (&$produced, &$closed): iterable {
                try {
                    ++$produced;
                    yield 'one';
                    ++$produced;
                    yield 'two';
                    ++$produced;
                    yield 'three';
                } finally {
                    $closed = true;
                }
            })(),
            ['x-status'],
            ['x-status' => 'ok'],
        );
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('write')->once()->with('one')->andReturnFalse();
        $swooleResponse->shouldNotReceive('trailer');
        $swooleResponse->shouldNotReceive('end');

        try {
            ResponseBridge::send($response, $swooleResponse);
            $this->fail('Expected the write failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to write the streamed response.', $exception->getMessage());
        }

        $this->assertSame(2, $produced);
        $this->assertTrue($closed);
    }

    public function testTrailerIterableWriteExceptionStopsProducerWithoutTrailersOrEnd(): void
    {
        $closed = false;
        $writeFailure = new RuntimeException('write failed');
        $response = new ResponseBridgeTrailerIterableResponse(
            (function () use (&$closed): iterable {
                try {
                    yield 'one';
                    yield 'two';
                } finally {
                    $closed = true;
                }
            })(),
            ['x-status'],
            ['x-status' => 'ok'],
        );
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('write')->once()->with('one')->andThrow($writeFailure);
        $swooleResponse->shouldNotReceive('trailer');
        $swooleResponse->shouldNotReceive('end');

        try {
            ResponseBridge::send($response, $swooleResponse);
            $this->fail('Expected the write failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($writeFailure, $exception);
        }

        $this->assertTrue($closed);
    }

    public function testSymfonyTrailerStreamWriteFailureDoesNotLeakOutputOrFinalize(): void
    {
        $response = new ResponseBridgeTrailerStreamedResponse(
            static function (): void {
                echo 'rejected';
                echo 'trigger';
                echo 'hidden';
            },
            ['x-status'],
            ['x-status' => 'ok'],
        );
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('write')->once()->with('rejected')->andReturnFalse();
        $swooleResponse->shouldNotReceive('trailer');
        $swooleResponse->shouldNotReceive('end');

        ob_start();

        try {
            ResponseBridge::send($response, $swooleResponse);
            $this->fail('Expected the write failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to write the streamed response.', $exception->getMessage());
        } finally {
            $output = ob_get_clean();
        }

        $this->assertSame('', $output);
    }

    public function testSymfonyTrailerStreamWriteFailureTakesPrecedenceOverProducerFailure(): void
    {
        $producerFailure = new RuntimeException('Producer failed.');
        $response = new ResponseBridgeTrailerStreamedResponse(
            function () use ($producerFailure): void {
                echo 'rejected';
                echo 'trigger';
                throw $producerFailure;
            },
            ['x-status'],
            ['x-status' => 'ok'],
        );
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('write')->once()->andReturnFalse();

        try {
            ResponseBridge::send($response, $swooleResponse);
            $this->fail('Expected the write failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to write the streamed response.', $exception->getMessage());
            $this->assertSame($producerFailure, $exception->getPrevious());
        }
    }

    public function testSymfonyTrailerStreamWriteExceptionTakesPrecedenceOverProducerFailure(): void
    {
        $writeFailure = new RuntimeException('Write failed.');
        $response = new ResponseBridgeTrailerStreamedResponse(
            static function (): void {
                echo 'rejected';
                echo 'trigger';
                throw new RuntimeException('Producer failed.');
            },
            ['x-status'],
            ['x-status' => 'ok'],
        );
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('write')->once()->andThrow($writeFailure);

        try {
            ResponseBridge::send($response, $swooleResponse);
            $this->fail('Expected the write failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($writeFailure, $exception);
        }
    }

    public function testStatusFailureThrowsBeforeHeaders(): void
    {
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('status')->once()->andReturnFalse();
        $swooleResponse->shouldNotReceive('header');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to set the response status.');

        ResponseBridge::send(new Response('body'), $swooleResponse);
    }

    public function testHeaderFailureThrowsBeforeBody(): void
    {
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('header')->with('X-Fail', 'value')->andReturnFalse();
        $swooleResponse->shouldNotReceive('end');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to set a response header.');

        ResponseBridge::send(new Response('body', headers: ['X-Fail' => 'value']), $swooleResponse);
    }

    public function testCookieFailureThrowsBeforeBody(): void
    {
        $response = new Response('body');
        $response->headers->setCookie(Cookie::create('failed', 'value'));
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('cookie')->once()->andReturnFalse();
        $swooleResponse->shouldNotReceive('end');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to set a response cookie.');

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testTrailerFailureThrowsBeforeEnd(): void
    {
        $response = new ResponseBridgeTrailerResponse('body', ['x-value'], ['x-value' => 'value']);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('trailer')->once()->andReturnFalse();
        $swooleResponse->shouldNotReceive('end');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to set a response trailer.');

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testFixedResponseEndFailureThrows(): void
    {
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('end')->once()->andReturnFalse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to complete the response.');

        ResponseBridge::send(new Response('body'), $swooleResponse);
    }

    public function testHeadResponseEndFailureThrows(): void
    {
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnFalse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to complete the response.');

        ResponseBridge::send(new Response('body'), $swooleResponse, withBody: false);
    }

    public function testTrailerResponseEndFailureThrows(): void
    {
        $response = new ResponseBridgeTrailerResponse('body', ['x-value'], ['x-value' => 'value']);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('end')->once()->with('body')->andReturnFalse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to complete the response.');

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testSendFileFailureThrows(): void
    {
        $path = $this->temporaryFile();
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('sendfile')->once()->with($path, 0, 0)->andReturnFalse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to send the response file.');

        ResponseBridge::send(new BinaryFileResponse($path), $swooleResponse);
    }

    private function mockSwooleResponse(): SwooleResponse
    {
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->byDefault()->andReturnTrue();
        $swooleResponse->shouldReceive('header')->byDefault()->andReturnTrue();
        $swooleResponse->shouldReceive('cookie')->byDefault()->andReturnTrue();
        $swooleResponse->shouldReceive('rawcookie')->byDefault()->andReturnTrue();
        $swooleResponse->shouldReceive('write')->byDefault()->andReturnTrue();
        $swooleResponse->shouldReceive('trailer')->byDefault()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->byDefault()->andReturnTrue();
        $swooleResponse->shouldReceive('sendfile')->byDefault()->andReturnTrue();

        return $swooleResponse;
    }

    private function temporaryFile(): string
    {
        $path = $this->tempDirectory . '/response.txt';
        file_put_contents($path, 'file contents');

        return $path;
    }

    /**
     * Send a streamed trailer response and capture its transport output.
     *
     * @return array{list<string>, ?string, null|array|string, array<string, string>}
     */
    private function sendTrailerStream(Response $response): array
    {
        $swooleResponse = $this->mockSwooleResponse();
        $writes = [];
        $endContent = null;
        $announcement = null;
        $trailers = [];

        $swooleResponse->shouldReceive('header')->andReturnUsing(function (string $name, string|array $value) use (&$announcement): bool {
            if (strtolower($name) === 'trailer') {
                $announcement = $value;
            }

            return true;
        });
        $swooleResponse->shouldReceive('write')->andReturnUsing(function (string $chunk) use (&$writes): bool {
            $writes[] = $chunk;

            return true;
        });
        $swooleResponse->shouldReceive('trailer')->andReturnUsing(function (string $name, string $value) use (&$trailers): bool {
            $trailers[$name] = $value;

            return true;
        });
        $swooleResponse->shouldReceive('end')->once()->andReturnUsing(function (...$arguments) use (&$endContent): bool {
            $endContent = $arguments[0] ?? null;

            return true;
        });

        ResponseBridge::send($response, $swooleResponse);

        return [$writes, $endContent, $announcement, $trailers];
    }
}

class ResponseBridgeWithFailingDeletion extends ResponseBridge
{
    /**
     * Simulate a binary response file deletion failure.
     */
    protected static function deleteBinaryFile(string $path): bool
    {
        return false;
    }
}

class ResponseBridgeTrailerResponse extends Response implements HasTrailers
{
    /**
     * @param list<string> $names
     * @param array<array-key, mixed>|(Closure(): array<array-key, mixed>) $finalTrailers
     */
    public function __construct(
        string $content,
        private readonly array $names,
        private readonly array|Closure $finalTrailers,
    ) {
        parent::__construct($content);
    }

    public function trailerNames(): array
    {
        return $this->names;
    }

    public function trailers(): array
    {
        return $this->finalTrailers instanceof Closure
            ? ($this->finalTrailers)()
            : $this->finalTrailers;
    }
}

class ResponseBridgeTrailerIterableResponse extends IterableStreamedResponse implements HasTrailers
{
    /**
     * @param iterable<string> $chunks
     * @param list<string> $names
     * @param array<array-key, mixed>|(Closure(): array<array-key, mixed>) $finalTrailers
     */
    public function __construct(
        iterable $chunks,
        private readonly array $names,
        private readonly array|Closure $finalTrailers,
    ) {
        parent::__construct($chunks);
    }

    public function trailerNames(): array
    {
        return $this->names;
    }

    public function trailers(): array
    {
        return $this->finalTrailers instanceof Closure
            ? ($this->finalTrailers)()
            : $this->finalTrailers;
    }
}

class ResponseBridgeTrailerStreamedResponse extends StreamedResponse implements HasTrailers
{
    /**
     * @param list<string> $names
     * @param array<array-key, mixed>|(Closure(): array<array-key, mixed>) $finalTrailers
     */
    public function __construct(
        Closure $callback,
        private readonly array $names,
        private readonly array|Closure $finalTrailers,
    ) {
        parent::__construct($callback);
    }

    public function trailerNames(): array
    {
        return $this->names;
    }

    public function trailers(): array
    {
        return $this->finalTrailers instanceof Closure
            ? ($this->finalTrailers)()
            : $this->finalTrailers;
    }
}

class ResponseBridgeTrailerBinaryFileResponse extends BinaryFileResponse implements HasTrailers
{
    public function trailerNames(): array
    {
        return ['x-value'];
    }

    public function trailers(): array
    {
        return ['x-value' => 'value'];
    }
}
