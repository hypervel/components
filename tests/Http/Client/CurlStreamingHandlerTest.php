<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client;

use CurlHandle;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\TransferStats;
use Hypervel\Engine\Coroutine;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\CurlStreamingHandler;
use Hypervel\Http\Client\Events\ConnectionFailed;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\RequestException;
use Hypervel\Tests\Http\Fixtures\LoopbackHttpServer;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;
use WeakReference;

use function Hypervel\Coroutine\parallel;

class CurlStreamingHandlerTest extends TestCase
{
    #[DataProvider('ambiguousHosts')]
    public function testAmbiguousHostsAreRejectedBeforeConnecting(string $url, array $headers, string $message): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage($message);

        (new Factory)->withOptions(['stream' => true])->withHeaders($headers)->get($url);
    }

    /**
     * Provide URI and Host-header spellings that transports interpret differently.
     */
    public static function ambiguousHosts(): array
    {
        return [
            ['http://127.0.0.1.:1/', [], 'must not be written as one to four'],
            ['http://127.0.0.1:1/', ['Host' => "example.test\xc2\xa0"], 'must contain only printable ASCII'],
        ];
    }

    public function testRedirectsCookiesAndDecompressionKeepThePublicClientBehavior(): void
    {
        $server = LoopbackHttpServer::start([
            ['status' => 302, 'headers' => ['Location' => '/final', 'Set-Cookie' => 'session=test; Path=/'], 'body' => 'redirect'],
            ['headers' => ['Content-Encoding' => 'gzip'], 'body' => gzencode("{\"id\":1}\n{\"id\":2}\n")],
        ]);
        $response = (new Factory)->withOptions(['stream' => true])->get('http://127.0.0.1:' . $server->port);

        try {
            $this->assertSame(200, $response->status());
            $this->assertSame([['id' => 1], ['id' => 2]], iterator_to_array($response->jsonLines()));
            $this->assertSame('', $response->header('Content-Encoding'));
            $server->request();
            $redirected = $server->request();
            $this->assertStringStartsWith('GET /final HTTP/1.1', $redirected);
            $this->assertStringContainsString('Cookie: session=test', $redirected);
        } finally {
            $response->close();
        }
    }

    #[DataProvider('authenticationResponses')]
    public function testNativeAuthenticationExposesOnlyTheFinalPostResponse(int $initialStatus): void
    {
        $server = LoopbackHttpServer::start([
            ['status' => $initialStatus, 'headers' => ['WWW-Authenticate' => 'Digest realm="test", nonce="fixed", qop="auth", algorithm=MD5'], 'body' => 'intermediate'],
            ['body' => 'final'],
        ]);
        $headers = [];
        $stats = [];
        $response = (new Factory)->withOptions([
            'stream' => true,
            'curl' => [CURLOPT_HTTPAUTH => CURLAUTH_DIGEST, CURLOPT_USERPWD => 'account:secret'],
            'on_headers' => static function (ResponseInterface $response) use (&$headers): void {
                $headers[] = $response->getStatusCode();
            },
            'on_stats' => static function (TransferStats $transfer) use (&$stats): void {
                $stats[] = $transfer->getResponse()->getStatusCode();
            },
        ])->withBody('prompt')->post('http://127.0.0.1:' . $server->port);

        try {
            $this->assertSame([200], $headers);
            $this->assertSame([200], $stats);
            $this->assertSame('final', $response->body());
            $this->assertSame([200], $stats);
            $this->assertStringEndsWith("\r\n\r\n", $server->request());
            $this->assertStringEndsWith("\r\n\r\nprompt", $server->request());
        } finally {
            $response->close();
        }
    }

    /**
     * Provide challenge and successful negotiation responses before the real POST.
     */
    public static function authenticationResponses(): array
    {
        return [[401], [200]];
    }

    public function testDigestAuthenticationWorksThroughThePublicApi(): void
    {
        $server = LoopbackHttpServer::start([
            ['status' => 401, 'headers' => ['WWW-Authenticate' => 'Digest realm="test", nonce="fixed", qop="auth", algorithm=MD5']],
            ['body' => 'authenticated'],
        ]);
        $response = (new Factory)->withOptions(['stream' => true])->withDigestAuth('account', 'secret')
            ->withBody('prompt')->post('http://127.0.0.1:' . $server->port);

        try {
            $this->assertSame(200, $response->status());
            $this->assertSame('authenticated', $response->body());
            $server->request();
            $authenticated = $server->request();
            $this->assertStringContainsString('Authorization: Digest ', $authenticated);
            $this->assertStringEndsWith("\r\n\r\nprompt", $authenticated);
        } finally {
            $response->close();
        }
    }

    #[DataProvider('callbackFailureStatuses')]
    public function testHeadersCallbackFailureKeepsItsResponseAndCauseAndClosesTheTransfer(int $status): void
    {
        $server = LoopbackHttpServer::start([['status' => $status]]);
        $failure = new RuntimeException('invalid provider headers');
        $stats = [];

        try {
            (new Factory)->withOptions([
                'stream' => true,
                'on_headers' => static fn () => throw $failure,
                'on_stats' => static function (TransferStats $transfer) use (&$stats): void {
                    $stats[] = $transfer;
                },
            ])->get('http://127.0.0.1:' . $server->port);

            $this->fail('Expected the header callback to fail.');
        } catch (ConnectionException|RequestException $exception) {
            if ($status === 200) {
                $this->assertInstanceOf(ConnectionException::class, $exception);
                $this->assertSame('An error was encountered during the on_headers event', $exception->getMessage());
                $this->assertSame($failure, $exception->getPrevious()->getPrevious());
            } else {
                $this->assertInstanceOf(RequestException::class, $exception);
                $this->assertSame($status, $exception->response->status());
            }
        }

        $this->assertCount(1, $stats);
        $this->assertSame($status, $stats[0]->getResponse()->getStatusCode());
        $this->assertSame($failure, $stats[0]->getHandlerErrorData()->getPrevious());
        $this->assertFalse($stats[0]->getResponse()->getBody()->isReadable());
        $this->assertNotNull($server->request());
    }

    /**
     * Provide successful and failed responses rejected by a header callback.
     */
    public static function callbackFailureStatuses(): array
    {
        return [[200], [503]];
    }

    public function testLateFinalHeadersRetainTheirResponseInTheExceptionAndStatistics(): void
    {
        $server = LoopbackHttpServer::start([['status' => 503, 'headers' => ['Retry-After' => '10']]]);
        $delayed = false;
        $stats = [];

        try {
            (new Factory)->timeout(0.5)->withOptions([
                'stream' => true,
                'progress' => static function (int $total, int $received) use (&$delayed): void {
                    if ($received > 0 && ! $delayed) {
                        $delayed = true;
                        // Headers exist, but native execution returns after their deadline.
                        usleep(600000);
                    }
                },
                'on_stats' => static function (TransferStats $transfer) use (&$stats): void {
                    $stats[] = $transfer;
                },
            ])->get('http://127.0.0.1:' . $server->port);

            $this->fail('Expected the late response to time out.');
        } catch (RequestException $exception) {
            $this->assertSame(503, $exception->response->status());
            $this->assertSame('10', $exception->response->header('Retry-After'));
        }

        $this->assertCount(1, $stats);
        $this->assertSame(503, $stats[0]->getResponse()->getStatusCode());
        $this->assertSame('Timed out while receiving the response headers', $stats[0]->getHandlerErrorData()->getMessage());
        $this->assertFalse($stats[0]->getResponse()->getBody()->isReadable());
        $this->assertTrue($stats[0]->getResponse()->getBody()->getMetadata('timed_out'));
    }

    public function testPreHeaderRetryDoesNotRecheckTheDeadlineAfterTheReplacementCallback(): void
    {
        $server = LoopbackHttpServer::start([['body' => ''], ['body' => 'retried']]);
        $nativeFactory = new CurlFactory(0);
        $first = true;
        $factory = m::mock(CurlFactoryInterface::class);
        $factory->shouldReceive('create')->andReturnUsing(static function (RequestInterface $request, array $options) use ($nativeFactory, &$first): EasyHandle {
            $easy = $nativeFactory->create($request, $options);

            if ($first) {
                $first = false;
                // Guzzle retries native success without a parsed response; reproduce that outcome.
                curl_setopt($easy->handle, CURLOPT_HEADERFUNCTION, static fn (CurlHandle $handle, string $header): int => strlen($header));
            }

            return $easy;
        });
        $factory->shouldReceive('release')->andReturnUsing($nativeFactory->release(...));
        $handler = new CurlStreamingHandler;
        (new ReflectionProperty($handler, 'factory'))->setValue($handler, $factory);
        $callbacks = 0;
        $response = (new Factory)->setHandler($handler)->timeout(0.5)->withOptions([
            'stream' => true,
            'on_headers' => static function () use (&$callbacks): void {
                ++$callbacks;
                usleep(600000);
            },
        ])->get('http://127.0.0.1:' . $server->port);

        try {
            $this->assertSame('retried', $response->body());
            $this->assertSame(1, $callbacks);
            $this->assertNotNull($server->request());
            $this->assertNotNull($server->request());
        } finally {
            $response->close();
        }
    }

    #[DataProvider('requestExecutionModes')]
    public function testWrappedHeaderCancellationIsNotRecoveredOrRetried(bool $async): void
    {
        $server = LoopbackHttpServer::start();
        $failure = new CanceledException('header processing canceled');
        $events = new Dispatcher;
        $events->listen(ConnectionFailed::class, fn () => $this->fail('Cancellation dispatched a connection failure.'));
        $attempts = 0;
        $request = (new Factory($events))->async($async)
            ->beforeSending(static function () use (&$attempts): void {
                ++$attempts;
            })
            ->afterResponse(fn () => $this->fail('Cancellation recovered an HTTP response.'))
            ->retry(3, when: fn () => $this->fail('Cancellation reached the retry policy.'))
            ->withOptions(['on_headers' => static fn () => throw $failure]);

        try {
            $result = $request->get('http://127.0.0.1:' . $server->port);

            if ($async) {
                $result->wait();
            }

            $this->fail('Expected the callback cancellation.');
        } catch (CanceledException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(1, $attempts);
        $this->assertNotNull($server->request());
    }

    /**
     * Provide synchronous and promise-based request execution.
     */
    public static function requestExecutionModes(): array
    {
        return [[false], [true]];
    }

    public function testCancellationDuringTrailerProcessingClosesTheBodyAndPreservesTheException(): void
    {
        $server = LoopbackHttpServer::start();
        $ready = new Channel(1);
        $waiting = new Channel(1);
        $cancellation = null;
        $response = (new Factory)->withOptions([
            'stream' => true,
            'on_trailers' => static function () use ($ready, $waiting, &$cancellation): void {
                $ready->push(Coroutine::id());

                try {
                    $waiting->pop(2);
                } catch (CanceledException $exception) {
                    $cancellation = $exception;

                    throw $exception;
                }
            },
        ])->get('http://127.0.0.1:' . $server->port);

        try {
            $results = parallel([
                'reader' => static function () use ($response): ?CanceledException {
                    try {
                        $response->body();

                        return null;
                    } catch (CanceledException $exception) {
                        return $exception;
                    }
                },
                'cancel' => function () use ($ready): void {
                    $coroutine = $ready->pop(1);
                    $this->assertIsInt($coroutine);
                    usleep(1000);
                    $this->assertTrue(Coroutine::cancelById($coroutine, true));
                },
            ]);

            $this->assertInstanceOf(CanceledException::class, $results['reader']);
            $this->assertSame($cancellation, $results['reader']);
            $this->assertFalse($response->toPsrResponse()->getBody()->isReadable());
        } finally {
            $response->close();
            $ready->close();
            $waiting->close();
        }
    }

    public function testAbandonedResponseBodyIsReleasedWithoutCyclicGarbageCollection(): void
    {
        $server = LoopbackHttpServer::start();
        $response = (new Factory)->withOptions(['stream' => true])->get('http://127.0.0.1:' . $server->port);
        $body = WeakReference::create($response->toPsrResponse()->getBody());

        unset($response);

        $this->assertNull($body->get());
        $this->assertNotNull($server->request());
    }

    public function testSlowConsumersPauseDownloadsAndMayContinueBeyondTheHeaderTimeout(): void
    {
        $payload = str_repeat('streamed content ', 131072);
        $server = LoopbackHttpServer::start([['body' => $payload]]);
        $downloaded = 0;
        $response = (new Factory)->timeout(0.5)->withOptions([
            'stream' => true,
            'read_timeout' => 0,
            'progress' => static function (int $total, int $received) use (&$downloaded): void {
                $downloaded = $received;
            },
        ])->get('http://127.0.0.1:' . $server->port);

        try {
            $body = $response->toPsrResponse()->getBody();
            $first = $body->read(1);
            $pausedAt = $downloaded;
            $this->assertGreaterThan(0, $pausedAt);
            $this->assertLessThan(strlen($payload), $pausedAt);

            // Allow the origin to progress while the caller holds its first byte.
            usleep(600000);
            $this->assertSame($pausedAt, $downloaded);
            $this->assertSame($payload, $first . $body->getContents());
        } finally {
            $response->close();
        }
    }
}
