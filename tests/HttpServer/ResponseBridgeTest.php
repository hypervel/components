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
use Hypervel\Server\ResponseCancellation;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use SplTempFileObject;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function Hypervel\Coroutine\parallel;

class ResponseBridgeTest extends TestCase
{
    use VerifiesDoubles;

    protected string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = ParallelTesting::tempDir('ResponseBridgeTest');
        (new Filesystem)->deleteDirectory($this->tempDirectory);
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

        $swooleResponse->expects('status')->with(200)->returns(true);
        $swooleResponse->expects('header')->times(minimum: 1)->returns(true);
        $swooleResponse->expects('end')->with('Hello World')->returns(true);

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testSendStatusCode(): void
    {
        $response = new Response('Not Found', 404);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->expects('status')->with(404)->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('end')->with('Not Found')->returns(true);

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
        $swooleResponse->expects('status')->with(200)->returns(true);
        $swooleResponse->allows('header')->resolves(function (string $name, string|array $value) use (&$sentHeaders): bool {
            $sentHeaders[$name] = $value;

            return true;
        });
        $swooleResponse->expects('end')->returns(true);

        ResponseBridge::send($response, $swooleResponse);

        $this->assertSame('application/json', $sentHeaders['Content-Type'] ?? $sentHeaders['content-type'] ?? null);
        $this->assertSame('abc-123', $sentHeaders['X-Request-Id'] ?? null);
        $this->assertSame(['one', 'two'], $sentHeaders['X-Repeated'] ?? null);
    }

    #[DataProvider('numericOnlyFieldNames')]
    public function testSendNumericOnlyHeaderNameAsString(string $name): void
    {
        $response = new Response('OK', headers: [$name => 'value']);
        $swooleResponse = $this->mockSwooleResponse('status', 'end');

        $swooleResponse->allows('header')->with(Argument::not($name), Argument::any())->returns(true);
        $swooleResponse->expects('header')->with($name, 'value')->returns(true);

        ResponseBridge::send($response, $swooleResponse);
    }

    public static function numericOnlyFieldNames(): array
    {
        return [
            'zero' => ['0'],
            'positive integer' => ['123'],
        ];
    }

    public function testSendCookies(): void
    {
        $response = new Response('OK', 200);
        $response->headers->setCookie(Cookie::create('session', 'abc123')->withPath('/')->withSecure(true));
        $swooleResponse = $this->mockSwooleResponse();

        $sentCookies = [];
        $swooleResponse->expects('status')->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->allows('cookie')->resolves(function (...$args) use (&$sentCookies) {
            $sentCookies[] = $args;
            return true;
        });
        $swooleResponse->expects('end')->returns(true);

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
        $swooleResponse = $this->mockSwooleResponse('status', 'header', 'end');

        $swooleResponse->expects('cookie')->never();
        $swooleResponse->expects('rawcookie')->with(
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
        )->returns(true);

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testSendWithoutBodyForHeadRequest(): void
    {
        $response = new Response('This body should not be sent', 200, ['Content-Length' => '28']);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->expects('status')->with(200)->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('end')->with()->returns(true);

        ResponseBridge::send($response, $swooleResponse, withBody: false);
    }

    public function testSendNonStreamedHypervelResponse(): void
    {
        $response = new HypervelResponse('Normal content', 200);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->expects('status')->with(200)->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('end')->with('Normal content')->returns(true);

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testSendBinaryFileResponse(): void
    {
        $path = $this->temporaryFile();
        $response = new BinaryFileResponse($path);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->expects('status')->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('sendfile')->with($path, 0, 0)->returns(true);

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testPreparedBinaryRangeUsesBoundedNativeSendfileArgumentsForHttpOne(): void
    {
        $path = $this->temporaryFile();
        $response = new BinaryFileResponse($path);
        $response->prepare(Request::create('/', 'GET', server: ['HTTP_RANGE' => 'bytes=2-5']));
        $swooleResponse = $this->mockSwooleResponse('header');

        $swooleResponse->expects('status')->with(206)->returns(true);
        $swooleResponse->expects('sendfile')->with($path, 2, 4)->returns(true);
        $swooleResponse->expects('write')->never();
        $swooleResponse->expects('end')->never();

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testHttpTwoBinaryResponseStreamsBoundedChunksWithoutConflictingFramingHeaders(): void
    {
        $path = $this->temporaryFile();
        $response = new BinaryFileResponse($path);
        $response->setChunkSize(4);
        $response->prepare(Request::create('/'));
        $response->headers->set('Transfer-Encoding', 'chunked');
        $swooleResponse = $this->mockSwooleResponse('status');
        $headers = [];
        $chunks = [];

        $swooleResponse->allows('header')->resolves(
            static function (string $name, string|array $value) use (&$headers): bool {
                $headers[strtolower($name)] = $value;

                return true;
            }
        );
        $swooleResponse->expects('sendfile')->never();
        $swooleResponse->expects('write')->times(4)->resolves(
            static function (string $chunk) use (&$chunks): bool {
                $chunks[] = $chunk;

                return true;
            }
        );
        $swooleResponse->expects('end')->with()->returns(true);

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
        $swooleResponse = $this->mockSwooleResponse('status', 'header');
        $chunks = [];

        $swooleResponse->expects('sendfile')->never();
        $swooleResponse->expects('write')->times(2)->resolves(
            static function (string $chunk) use (&$chunks): bool {
                $chunks[] = $chunk;

                return true;
            }
        );
        $swooleResponse->expects('end')->with()->returns(true);

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
        $swooleResponse = $this->mockSwooleResponse('status', 'header');
        $chunks = [];

        $swooleResponse->expects('sendfile')->never();
        $swooleResponse->allows('write')->resolves(
            static function (string $chunk) use (&$chunks): bool {
                $chunks[] = $chunk;

                return true;
            }
        );
        $swooleResponse->expects('end')->with()->returns(true);

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
        $swooleResponse = $this->mockSwooleResponse('status');
        $headers = [];
        $chunks = [];

        $swooleResponse->allows('header')->resolves(
            static function (string $name, string|array $value) use (&$headers): bool {
                $headers[strtolower($name)] = $value;

                return true;
            }
        );
        $swooleResponse->expects('sendfile')->never();
        $swooleResponse->allows('write')->resolves(
            static function (string $chunk) use (&$chunks): bool {
                $chunks[] = $chunk;

                return true;
            }
        );
        $swooleResponse->expects('end')->with()->returns(true);

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
        $swooleResponse = $this->mockSwooleResponse('status', 'header');

        $swooleResponse->expects('write')->returns(false);
        $swooleResponse->expects('end')->with()->returns(false);

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
        $swooleResponse = $this->mockSwooleResponse('status', 'header');

        $swooleResponse->expects('write')->with('short')->returns(true);
        $swooleResponse->expects('end')->with()->returns(true);

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
        $swooleResponse = $this->mockSwooleResponse('status', 'header');

        $swooleResponse->allows('write')->returns(true);
        $swooleResponse->expects('end')->with()->returns(false);

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

        $swooleResponse->expects('status')->returns(false);
        $swooleResponse->expects('header')->never();
        $swooleResponse->expects('write')->never();
        $swooleResponse->expects('end')->with()->returns(false);

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
        $swooleResponse = $this->mockSwooleResponse('status', 'header', 'write');
        $swooleResponse->expects('end')->with()->returns(true);
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
            $swooleResponse = $this->mockSwooleResponse('status', 'header');

            $swooleResponse->expects('sendfile')->never();
            $swooleResponse->expects('write')->never();
            $swooleResponse->expects('end')->with()->returns(true);

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
        $swooleResponse = $this->mockSwooleResponse('status', 'header');

        $swooleResponse->expects('sendfile')->never();
        $swooleResponse->expects('write')->never();
        $swooleResponse->expects('end')->with()->returns(true);

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
            $swooleResponse = $this->mockSwooleResponse('status');

            $swooleResponse->expects('sendfile')->never();
            $swooleResponse->expects('write')->never();
            $swooleResponse->allows('header')->with(Argument::not('X-Sendfile'), Argument::any())->returns(true);
            $swooleResponse->expects('header')->with('X-Sendfile', $path)->returns(true);
            $swooleResponse->expects('end')->with()->returns(true);

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
                'HTTP_X_SENDFILE_TYPE' => 'X-LIGHTTPD-send-file',
            ]);
            $response = new BinaryFileResponse($path);
            $response->deleteFileAfterSend();
            $response->prepare($request);
            $swooleResponse = $this->mockSwooleResponse('status');

            $swooleResponse->expects('sendfile')->never();
            $swooleResponse->expects('write')->never();
            $swooleResponse->allows('header')->with(Argument::not('X-LIGHTTPD-send-file'), Argument::any())->returns(true);
            $swooleResponse->expects('header')->with('X-LIGHTTPD-send-file', $path)->returns(true);
            $swooleResponse->expects('end')->with()->returns(true);

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
        $swooleResponse->expects('status')->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->allows('write')->resolves(function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;

            return true;
        });
        $swooleResponse->expects('end')->with()->returns(false);

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
        $swooleResponse->expects('status')->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('write')->with('first')->returns(false);
        $swooleResponse->expects('end')->with()->returns(false);

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
        $swooleResponse->expects('status')->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('write')->throws(new RuntimeException('write failed'));
        $swooleResponse->expects('end')->throws(new RuntimeException('end failed'));

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
        $swooleResponse->expects('status')->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('write')->with('first')->returns(false);
        $swooleResponse->expects('end')->with()->returns(false);

        ResponseBridge::send($response, $swooleResponse);

        $this->assertTrue($callbackCompleted);
    }

    public function testOrdinaryStreamedResponsePreservesWriteFailureAndStillEnds(): void
    {
        $response = new StreamedResponse(static function (): void {
            echo 'first';
        });
        $swooleResponse = $this->mockSwooleResponse();
        $swooleResponse->expects('status')->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('write')->throws(new RuntimeException('write failed'));
        $swooleResponse->expects('end')->throws(new RuntimeException('end failed'));

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
        $swooleResponse->expects('status')->returns(true);
        $swooleResponse->allows('header')->resolves(function (string $name, string|array $value) use (&$sentHeaders): bool {
            $sentHeaders[$name] = $value;

            return true;
        });
        $swooleResponse->allows('write')->returns(true);
        $swooleResponse->expects('end')->returns(false);

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
        $swooleResponse->expects('status')->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->allows('write')->returns(true);
        $swooleResponse->expects('end')->returns(false);

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
        $swooleResponse->expects('status')->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->allows('cookie')->resolves(function (string $name) use (&$cookieNames): bool {
            $cookieNames[] = $name;

            return true;
        });
        $swooleResponse->expects('end')->returns(true);

        ResponseBridge::send($response, $swooleResponse);

        $this->assertContains('first', $cookieNames);
        $this->assertContains('second', $cookieNames);
    }

    public function testSendEmptyBodyResponse(): void
    {
        $response = new Response('', 204);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->expects('status')->with(204)->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('end')->with('')->returns(true);

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
        $swooleResponse = $this->mockSwooleResponse('status');
        $headers = [];

        $swooleResponse->allows('header')->resolves(function (string $name, string|array $value) use (&$headers): bool {
            $headers[strtolower($name)] = $value;

            return true;
        });
        $swooleResponse->expects('write')->never();
        $swooleResponse->expects('trailer')->never();
        $swooleResponse->expects('end')->with()->returns(true);

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

        $swooleResponse->expects('status')->never();
        $swooleResponse->expects('header')->never();
        $swooleResponse->expects('sendfile')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Binary file responses cannot emit trailers.');

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

        $swooleResponse->expects('status')->resolves(function () use (&$operations): bool {
            $operations[] = 'status';

            return true;
        });
        $swooleResponse->allows('header')->resolves(function (string $name) use (&$operations): bool {
            if (strtolower($name) === 'trailer') {
                $operations[] = 'announce';
            }

            return true;
        });
        $swooleResponse->expects('trailer')->with('x-final', 'value')->resolves(function () use (&$operations): bool {
            $operations[] = 'trailer';

            return true;
        });
        $swooleResponse->expects('end')->with()->resolves(function () use (&$operations): bool {
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
        $swooleResponse = $this->mockSwooleResponse('status', 'end', 'trailer');
        $announcement = null;

        $swooleResponse->allows('header')->resolves(function (string $name, string|array $value) use (&$announcement): bool {
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

        $swooleResponse->expects('status')->never();
        $swooleResponse->expects('header')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs($message);

        ResponseBridge::send($response, $swooleResponse);
    }

    #[DataProvider('invalidTrailerNames')]
    public function testInvalidFinalTrailerNameFailsAtTrailerBoundary(string $name, string $message): void
    {
        $response = new ResponseBridgeTrailerResponse('body', [], [$name => 'value']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs($message);

        ResponseBridge::send($response, $this->mockSwooleResponse('status', 'header'));
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
        $this->expectExceptionMessageIs('Response trailer names must be unique after normalization.');

        ResponseBridge::send($response, $this->mockSwooleResponse('status', 'header'));
    }

    #[DataProvider('numericOnlyFieldNames')]
    public function testNumericOnlyFinalTrailerNameIsEmittedAsString(string $name): void
    {
        $response = new ResponseBridgeTrailerResponse('body', [$name], [$name => 'value']);
        $swooleResponse = $this->mockSwooleResponse('status', 'end');

        $swooleResponse->allows('header')->with(Argument::not('Trailer'), Argument::any())->returns(true);
        $swooleResponse->expects('header')->with('Trailer', $name)->returns(true);
        $swooleResponse->expects('trailer')->with($name, 'value')->returns(true);

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testFinalTrailerValuesMustBeStrings(): void
    {
        $response = new ResponseBridgeTrailerResponse('body', [], ['x-value' => 123]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Response trailer values must be strings.');

        ResponseBridge::send($response, $this->mockSwooleResponse('status', 'header'));
    }

    public function testFinalTrailerValuesCannotContainLineBreaks(): void
    {
        $response = new ResponseBridgeTrailerResponse('body', [], ['x-value' => "one\r\ntwo"]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Response trailer values cannot contain line breaks.');

        ResponseBridge::send($response, $this->mockSwooleResponse('status', 'header'));
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
        $swooleResponse = $this->mockSwooleResponse('status', 'header');

        $swooleResponse->expects('write')->with('one')->resolves(function () use (&$operations): bool {
            $operations[] = 'write-one';

            return true;
        });
        $swooleResponse->expects('trailer')->resolves(function () use (&$operations): bool {
            $operations[] = 'trailer';

            return true;
        });
        $swooleResponse->expects('end')->with('two')->resolves(function () use (&$operations): bool {
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
        $swooleResponse = $this->mockSwooleResponse('status', 'header');

        $swooleResponse->expects('write')->with('one')->returns(false);
        $swooleResponse->expects('trailer')->never();
        $swooleResponse->expects('end')->never();

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
        $swooleResponse = $this->mockSwooleResponse('status', 'header');

        $swooleResponse->expects('write')->with('one')->throws($writeFailure);
        $swooleResponse->expects('trailer')->never();
        $swooleResponse->expects('end')->never();

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
        $swooleResponse = $this->mockSwooleResponse('status', 'header');

        $swooleResponse->expects('write')->with('rejected')->returns(false);
        $swooleResponse->expects('trailer')->never();
        $swooleResponse->expects('end')->never();

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
        $swooleResponse = $this->mockSwooleResponse('status', 'header');

        $swooleResponse->expects('write')->returns(false);

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
        $swooleResponse = $this->mockSwooleResponse('status', 'header');

        $swooleResponse->expects('write')->throws($writeFailure);

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

        $swooleResponse->expects('status')->returns(false);
        $swooleResponse->expects('header')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to set the response status.');

        ResponseBridge::send(new Response('body'), $swooleResponse);
    }

    #[DataProvider('disconnectCancellationOptions')]
    public function testDisconnectCancelsAllActiveStreamsOnOnlyThatConnection(bool $cancel): void
    {
        $ready = new Channel(3);
        $continue = new Channel(3);
        $closed = [];
        $produce = function (int $connection, string $producer) use ($ready, $continue, $cancel, &$closed): bool {
            $response = new IterableStreamedResponse((function () use ($producer, $ready, $continue, &$closed): iterable {
                try {
                    $ready->push(true);
                    $this->assertTrue($continue->pop(2));
                    yield 'body';
                } finally {
                    $closed[] = $producer;
                }
            })());
            $native = $this->mockSwooleResponse('status', 'header', 'end', 'write');
            $native->fd = $connection;

            if ($cancel) {
                $response->cancelOnDisconnect();
                $native->expects('isWritable')->returns(true);
            }

            try {
                ResponseBridge::send($response, $native);

                return false;
            } catch (CanceledException) {
                return true;
            }
        };

        try {
            $results = parallel([
                'first' => fn (): bool => $produce(10, 'first'),
                'second' => fn (): bool => $produce(10, 'second'),
                'other' => fn (): bool => $produce(20, 'other'),
                'close' => function () use ($ready, $continue, $cancel): void {
                    for ($index = 0; $index < 3; ++$index) {
                        $this->assertTrue($ready->pop(1));
                    }

                    usleep(1000);
                    ResponseCancellation::cancel(10);

                    for ($index = 0; $index < ($cancel ? 1 : 3); ++$index) {
                        $continue->push(true);
                    }
                },
            ]);

            $this->assertSame($cancel, $results['first']);
            $this->assertSame($cancel, $results['second']);
            $this->assertFalse($results['other']);
            $this->assertEqualsCanonicalizing(['first', 'second', 'other'], $closed);
        } finally {
            $ready->close();
            $continue->close();
        }
    }

    /**
     * Provide default production and explicit disconnect cancellation.
     */
    public static function disconnectCancellationOptions(): array
    {
        return [
            'default opt-out' => [false],
            'opt-in' => [true],
        ];
    }

    public function testDisconnectAfterProductionDoesNotCancelLaterRequestWork(): void
    {
        $sent = new Channel(1);
        $continue = new Channel(1);

        try {
            $results = parallel([
                'producer' => function () use ($sent, $continue): mixed {
                    $response = (new IterableStreamedResponse(['body']))->cancelOnDisconnect();
                    $native = $this->mockSwooleResponse('status', 'header', 'end', 'write');
                    $native->fd = 10;
                    $native->expects('isWritable')->returns(true);
                    ResponseBridge::send($response, $native);
                    $sent->push(true);

                    return $continue->pop(2);
                },
                'close' => function () use ($sent, $continue): void {
                    $this->assertTrue($sent->pop(1));
                    usleep(1000);
                    ResponseCancellation::cancel(10);
                    $continue->push(true);
                },
            ]);

            $this->assertTrue($results['producer']);
        } finally {
            $sent->close();
            $continue->close();
        }
    }

    public function testAlreadyDisconnectedClientDoesNotStartAnOptedInProducer(): void
    {
        $started = false;
        $response = (new IterableStreamedResponse((static function () use (&$started): iterable {
            $started = true;
            yield 'body';
        })()))->cancelOnDisconnect();
        $native = $this->mockSwooleResponse('status', 'header', 'end');
        $native->expects('isWritable')->returns(false);
        $native->expects('write')->never();

        try {
            ResponseBridge::send($response, $native);
            $this->fail('Expected cancellation before producing the response.');
        } catch (CanceledException) {
            $this->assertFalse($started);
        }
    }

    public function testHeaderFailureThrowsBeforeBody(): void
    {
        $swooleResponse = $this->mockSwooleResponse('status');

        $swooleResponse->allows('header')->with(Argument::not('X-Fail'), Argument::any())->returns(true);
        $swooleResponse->allows('header')->with('X-Fail', 'value')->returns(false);
        $swooleResponse->expects('end')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to set a response header.');

        ResponseBridge::send(new Response('body', headers: ['X-Fail' => 'value']), $swooleResponse);
    }

    public function testCookieFailureThrowsBeforeBody(): void
    {
        $response = new Response('body');
        $response->headers->setCookie(Cookie::create('failed', 'value'));
        $swooleResponse = $this->mockSwooleResponse('status', 'header');

        $swooleResponse->expects('cookie')->returns(false);
        $swooleResponse->expects('end')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to set a response cookie.');

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testTrailerFailureThrowsBeforeEnd(): void
    {
        $response = new ResponseBridgeTrailerResponse('body', ['x-value'], ['x-value' => 'value']);
        $swooleResponse = $this->mockSwooleResponse('status', 'header');

        $swooleResponse->expects('trailer')->returns(false);
        $swooleResponse->expects('end')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to set a response trailer.');

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testFixedResponseEndFailureThrows(): void
    {
        $swooleResponse = $this->mockSwooleResponse('status', 'header');

        $swooleResponse->expects('end')->returns(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to complete the response.');

        ResponseBridge::send(new Response('body'), $swooleResponse);
    }

    public function testHeadResponseEndFailureThrows(): void
    {
        $swooleResponse = $this->mockSwooleResponse('status', 'header');

        $swooleResponse->expects('end')->with()->returns(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to complete the response.');

        ResponseBridge::send(new Response('body'), $swooleResponse, withBody: false);
    }

    public function testTrailerResponseEndFailureThrows(): void
    {
        $response = new ResponseBridgeTrailerResponse('body', ['x-value'], ['x-value' => 'value']);
        $swooleResponse = $this->mockSwooleResponse('status', 'header', 'trailer');

        $swooleResponse->expects('end')->with('body')->returns(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to complete the response.');

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testSendFileFailureThrows(): void
    {
        $path = $this->temporaryFile();
        $swooleResponse = $this->mockSwooleResponse('status', 'header');

        $swooleResponse->expects('sendfile')->with($path, 0, 0)->returns(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to send the response file.');

        ResponseBridge::send(new BinaryFileResponse($path), $swooleResponse);
    }

    /**
     * Create a strict response double with only incidental successful operations stubbed.
     */
    private function mockSwooleResponse(string ...$successfulMethods): SwooleResponse&DoubleInterface
    {
        $response = Double::for(SwooleResponse::class)->strict();

        foreach ($successfulMethods as $method) {
            $response->allows($method)->returns(true);
        }

        return $response;
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
        $swooleResponse = $this->mockSwooleResponse('status');
        $writes = [];
        $endContent = null;
        $announcement = null;
        $trailers = [];

        $swooleResponse->allows('header')->resolves(function (string $name, string|array $value) use (&$announcement): bool {
            if (strtolower($name) === 'trailer') {
                $announcement = $value;
            }

            return true;
        });
        $swooleResponse->allows('write')->resolves(function (string $chunk) use (&$writes): bool {
            $writes[] = $chunk;

            return true;
        });
        $swooleResponse->allows('trailer')->resolves(function (string $name, string $value) use (&$trailers): bool {
            $trailers[$name] = $value;

            return true;
        });
        $swooleResponse->expects('end')->resolves(function (...$arguments) use (&$endContent): bool {
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
