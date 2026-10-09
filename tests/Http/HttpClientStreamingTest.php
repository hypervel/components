<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\StreamHandler;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\CurlStreamingHandler;
use Hypervel\Http\Client\Factory;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;
use Symfony\Component\Process\Process;
use Throwable;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

class HttpClientStreamingTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[DataProvider('handlerModes')]
    public function testStreamingRequiresAnAvailableDefaultHandler(string $mode, int $exitCode, string $output): void
    {
        $process = new Process([
            PHP_BINARY, '-d', 'allow_url_fopen=0', __DIR__ . '/Fixtures/streaming-handler.php', $mode,
        ]);
        $process->setTimeout(5);
        $process->run();

        $this->assertSame($exitCode, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame($output, $exitCode === 0 ? $process->getOutput() : $process->getErrorOutput());
    }

    /**
     * Provide default and caller-supplied transport configurations.
     */
    public static function handlerModes(): array
    {
        return [
            'default handler unavailable' => ['default', 1, 'Streaming responses require allow_url_fopen when using the default HTTP handler.'],
            'caller owns the handler' => ['handler', 0, 'custom handler'],
            'caller owns the client' => ['client', 0, 'custom client'],
            'caller owns the stack' => ['stack', 0, 'custom stack'],
            'fake streaming response' => ['fake', 0, 'fake stream'],
            'buffered requests remain supported' => ['buffered', 0, 'buffered'],
        ];
    }

    public function testStreamingReadsAllowOtherCoroutinesToProgress(): void
    {
        $this->withStreamingServer('delayed', function (string $address): void {
            $ready = new Channel(1);
            try {
                $results = parallel([
                    'reader' => function () use ($address, $ready): array {
                        $response = (new Factory)->withOptions(['stream' => true, 'read_timeout' => 3])->get('http://' . $address);
                        try {
                            $ready->push(true);

                            return iterator_to_array($response->jsonLines());
                        } finally {
                            $response->close();
                        }
                    },
                    'release' => function () use ($address, $ready): void {
                        $this->assertTrue($ready->pop(1), 'The streaming response headers did not arrive.');
                        usleep(10000);
                        $this->releaseServer($address);
                    },
                ]);

                $this->assertSame([['id' => 2]], $results['reader']);
            } finally {
                $ready->close();
            }
        });
    }

    public function testConcurrentPhpStreamsReleaseTheirResponseHeaders(): void
    {
        // @TODO: Update the version gate when Swoole releases the response-header ownership fix.
        if (SWOOLE_VERSION_ID <= 60203) {
            $this->markTestSkipped('Swoole 6.2.3 and earlier do not isolate PHP response-header ownership between coroutines.');
        }

        $process = new Process([PHP_BINARY, __DIR__ . '/Fixtures/concurrent-stream-headers.php']);
        $process->setTimeout(10);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $samples = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(3, $samples);

        // Allow allocator bookkeeping, but not a retained batch of 16 KiB headers.
        $this->assertLessThan(131072, max($samples) - min($samples), $process->getOutput());
    }

    public function testConcurrentFallbackRequestsKeepIndependentDeadlines(): void
    {
        // @TODO: Update this version gate once https://github.com/guzzle/guzzle/pull/3934 ships.
        if (ClientInterface::MAJOR_VERSION >= 8) {
            $this->markTestSkipped('Guzzle 8 stores concurrent StreamHandler request deadlines on the shared handler.');
        }

        $process = new Process([PHP_BINARY, __DIR__ . '/Fixtures/concurrent-stream-deadlines.php']);
        $process->setTimeout(10);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('OK', json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testFallbackJsonLinesArriveBeforeTheNextChunk(): void
    {
        // @TODO: Enable this test for versions containing https://github.com/guzzle/guzzle/pull/3936.
        $this->markTestSkipped('Guzzle holds later chunked response data until the read buffer fills or times out.');

        // Without native cURL hooks, the default handler uses PHP streams.
        $this->withStreamingServer('chunked', function (string $address): void {
            $response = (new Factory)->withOptions(['stream' => true, 'read_timeout' => 3])
                ->get('http://' . $address);

            try {
                $this->releaseServer($address);
                $lines = $response->jsonLines();
                $first = $lines->current();
                $timedOut = $response->toPsrResponse()->getBody()->getMetadata('timed_out');
                $this->releaseServer($address);

                $this->assertSame(['id' => 1], $first);
                $this->assertFalse($timedOut, 'The first record was withheld until the read timeout.');
                $lines->next();
                $this->assertSame(['id' => 2], $lines->current());
                $lines->next();
                $this->assertFalse($lines->valid());
            } finally {
                $response->close();
            }
        }, 0);
    }

    #[DataProvider('streamingTransports')]
    public function testBufferedFirstRecordArrivesBeforeTheNextServerWrite(bool $native, string $mode): void
    {
        if (! $native && SWOOLE_VERSION_ID <= 60203) {
            $this->markTestSkipped('Swoole 6.2.3 and earlier lack the buffered-read fix: https://github.com/swoole/swoole-src/pull/6235.');
        }

        $this->withStreamingServer($mode, function (string $address) use ($native, $mode): void {
            $received = new Channel(1);
            try {
                $results = parallel([
                    'reader' => function () use ($address, $received, $native, $mode): array {
                        $response = (new Factory)->withOptions(['stream' => true, 'read_timeout' => 3])
                            ->setHandler($native ? new CurlStreamingHandler : new StreamHandler)
                            ->get('http://' . $address);
                        try {
                            if ($mode === 'chunked') {
                                $this->releaseServer($address);
                            }

                            $lines = $response->jsonLines();
                            $first = $lines->current();
                            $received->push($first);
                            $lines->next();
                            $last = $lines->current();
                            $lines->next();

                            return [$first, $last, $lines->valid()];
                        } finally {
                            $response->close();
                        }
                    },
                    'release' => function () use ($address, $received): mixed {
                        $first = $received->pop(1);
                        $this->releaseServer($address);

                        return $first;
                    },
                ]);

                $this->assertSame(['id' => 1], $results['release'], 'The first record was withheld until the server was released.');
                $this->assertSame([['id' => 1], ['id' => 2], false], $results['reader']);
            } finally {
                $received->close();
            }
        });
    }

    /**
     * Provide the native streaming route and its PHP-stream fallback.
     */
    public static function streamingTransports(): array
    {
        return [
            'native cURL with buffered data' => [true, 'buffered'],
            'native cURL with later chunked data' => [true, 'chunked'],
            'PHP stream fallback' => [false, 'buffered'],
        ];
    }

    public function testInformationalHeadersAreNotExposedAsTheFinalResponse(): void
    {
        $this->withStreamingServer('informational', function (string $address): void {
            $statuses = [];
            $response = (new Factory)->withOptions([
                'stream' => true,
                'on_headers' => static function (ResponseInterface $response) use (&$statuses): void {
                    $statuses[] = $response->getStatusCode();
                },
            ])->get('http://' . $address);

            try {
                $this->assertSame([200], $statuses);
                $this->assertSame('final', $response->body());
            } finally {
                $response->close();
            }
        });
    }

    public function testTruncatedBodiesFailInsteadOfReportingSuccessfulEndOfStream(): void
    {
        $this->withStreamingServer('truncated', function (string $address): void {
            $response = (new Factory)->withOptions(['stream' => true])->get('http://' . $address);

            try {
                $this->assertSame('partial', $response->toPsrResponse()->getBody()->read(8192));
                $this->releaseServer($address);
                $response->body();

                $this->fail('Expected a truncated-response error.');
            } catch (TransferException $exception) {
                $this->assertStringContainsString('cURL error 18', $exception->getMessage());
                $this->assertFalse($response->toPsrResponse()->getBody()->isReadable());
            } finally {
                $response->close();
            }
        });
    }

    #[DataProvider('trailerResponses')]
    public function testTrailersAreDeliveredOnceAfterHeadersAndTransferCompletion(string $mode, string $body): void
    {
        $this->withStreamingServer($mode, function (string $address) use ($mode, $body): void {
            $trailers = [];
            $events = [];
            $response = (new Factory)->withOptions([
                'stream' => true,
                'on_headers' => static function () use (&$events): void {
                    $events[] = 'headers';
                },
                'on_trailers' => static function (array $headers) use (&$trailers, &$events): void {
                    $events[] = 'trailers';
                    $trailers[] = $headers;
                },
            ])->get('http://' . $address);

            try {
                if ($mode === 'streamed-trailers') {
                    $this->assertSame([], $trailers);
                }

                $this->assertSame($body, $response->body());
                $this->assertSame(['headers', 'trailers'], $events);
                $this->assertSame([['x-checksum' => ['abc']]], $trailers);
                $this->assertSame('', $response->body());
                $this->assertCount(1, $trailers);
            } finally {
                $response->close();
            }
        });
    }

    /**
     * Provide transfers that complete before exposure and during consumption.
     */
    public static function trailerResponses(): array
    {
        return [
            'small response' => ['trailers', 'hello'],
            'streamed response' => ['streamed-trailers', str_repeat('hello', 8192)],
        ];
    }

    public function testCancelingASilentBodyReadFinishesBeforeTheProviderContinues(): void
    {
        $this->withStreamingServer('delayed', function (string $address): void {
            $ready = new Channel(1);
            $finished = new Channel(1);

            try {
                $results = parallel([
                    'reader' => function () use ($address, $ready, $finished): ?CanceledException {
                        $response = (new Factory)->withOptions(['stream' => true])->get('http://' . $address);

                        try {
                            $ready->push(EngineCoroutine::id());
                            $response->lines()->current();

                            return null;
                        } catch (CanceledException $exception) {
                            return $exception;
                        } finally {
                            $response->close();
                            $finished->push(true);
                        }
                    },
                    'cancel' => function () use ($address, $ready, $finished): bool {
                        try {
                            $coroutine = $ready->pop(1);
                            $this->assertIsInt($coroutine);
                            // Channel delivery resumes us before the reader enters its I/O wait.
                            usleep(1000);
                            $this->assertTrue(EngineCoroutine::cancelById($coroutine));

                            return $finished->pop(1) === true;
                        } finally {
                            $this->releaseServer($address);
                        }
                    },
                ]);

                $this->assertInstanceOf(CanceledException::class, $results['reader']);
                $this->assertTrue($results['cancel'], 'The reader waited for provider data after cancellation.');
            } finally {
                $ready->close();
                $finished->close();
            }
        });
    }

    public function testAResponseCanBeConsumedAfterItsCreatingCoroutineEnds(): void
    {
        $this->withStreamingServer('buffered', function (string $address): void {
            [$response] = parallel([
                static fn () => (new Factory)->withOptions(['stream' => true])->get('http://' . $address),
            ]);

            try {
                $lines = $response->jsonLines();
                $this->assertSame(['id' => 1], $lines->current());
                $this->releaseServer($address);
                $lines->next();
                $this->assertSame(['id' => 2], $lines->current());
                $lines->next();
                $this->assertFalse($lines->valid());
            } finally {
                $response->close();
            }
        });
    }

    #[DataProvider('connectionRoutes')]
    public function testNamedStreamsReuseConnectionsWithoutSharingCredentialsOrCookies(bool $proxy): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__) . '/HttpServer/Fixtures/disconnect-server.php', '', 'process']);
        $process->setTimeout(10);
        $process->start();
        $processId = $process->getPid();

        try {
            $deadline = microtime(true) + 3;

            while (! str_contains($process->getOutput(), 'READY ') && $process->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }

            $this->assertSame(1, preg_match('/READY (\d+)/', $process->getOutput(), $matches), $process->getErrorOutput());
            $endpoint = 'http://127.0.0.1:' . $matches[1];
            $url = ($proxy ? 'http://provider.invalid' : $endpoint) . '/identity';
            $failure = null;

            run(function () use ($url, $endpoint, $proxy, &$failure): void {
                try {
                    $factory = (new Factory)->registerConnection('provider');
                    $connections = [];
                    $tokens = ['first-account', 'second-account', 'first-account', null, 'second-account'];

                    for ($index = 3; $index <= CurlStreamingHandler::MAX_IDLE_CONNECTIONS; ++$index) {
                        $tokens[] = 'account-' . $index;
                    }

                    $tokens[] = 'account-' . CurlStreamingHandler::MAX_IDLE_CONNECTIONS;
                    $tokens[] = 'first-account';

                    foreach ($tokens as $token) {
                        $request = $factory->connection('provider')->withOptions(['stream' => true]);

                        if ($proxy) {
                            $request->withOptions([
                                'proxy' => $endpoint,
                                'curl' => [
                                    CURLOPT_HTTPPROXYTUNNEL => true,
                                    CURLOPT_PROXYHEADER => $token === null ? [] : ['Proxy-Authorization: Bearer ' . $token],
                                ],
                            ]);
                        }

                        if ($token !== null) {
                            $request->withToken($token);
                        }

                        $response = $request->get($url);

                        try {
                            $identity = $response->json();
                            $connections[] = $identity['connection'];
                            $this->assertSame($token === null ? null : 'Bearer ' . $token, $identity['authorization']);
                            $this->assertSame($proxy && $token !== null ? 'Bearer ' . $token : null, $identity['proxy_authorization']);
                            $this->assertNull($identity['cookie']);
                        } finally {
                            $response->close();
                        }
                    }

                    $this->assertCount($proxy ? CurlStreamingHandler::MAX_IDLE_CONNECTIONS + 2 : 1, array_unique($connections));
                    $this->assertSame($connections[0], $connections[2]);
                    $this->assertSame($connections[1], $connections[4]);
                    $this->assertSame($connections[count($connections) - 3], $connections[count($connections) - 2]);

                    if ($proxy) {
                        $this->assertNotSame($connections[0], end($connections), 'The least recently used identity was not evicted.');
                    }
                } catch (Throwable $exception) {
                    $failure = $exception;
                }
            }, SWOOLE_HOOK_ALL);

            if ($failure !== null) {
                throw $failure;
            }
        } finally {
            posix_kill(-$processId, SIGKILL);
            $process->stop(0);
        }
    }

    /**
     * Provide direct requests and connection-authenticated proxy tunnels.
     */
    public static function connectionRoutes(): array
    {
        return [[false], [true]];
    }

    public function testCancelingAHeaderWaitFinishesBeforeTheProviderResponds(): void
    {
        $this->withStreamingServer('silent-headers', function (string $address): void {
            $started = new Channel(1);
            $finished = new Channel(1);

            try {
                $results = parallel([
                    'reader' => function () use ($address, $started, $finished): ?CanceledException {
                        $started->push(EngineCoroutine::id());

                        try {
                            (new Factory)->withOptions(['stream' => true])->get('http://' . $address);

                            return null;
                        } catch (CanceledException $exception) {
                            return $exception;
                        } finally {
                            $finished->push(true);
                        }
                    },
                    'cancel' => function () use ($address, $started, $finished): void {
                        $coroutine = $started->pop(1);
                        $control = stream_socket_client('tcp://' . $address, $error, $message, 2);
                        $this->assertIsResource($control, $message);

                        try {
                            stream_set_timeout($control, 2);
                            $this->assertSame("ready\n", fgets($control));
                            $this->assertTrue(EngineCoroutine::cancelById($coroutine));
                            $this->assertTrue($finished->pop(1), 'The header wait continued until the provider responded.');
                        } finally {
                            fclose($control);
                        }
                    },
                ]);

                $this->assertInstanceOf(CanceledException::class, $results['reader']);
            } finally {
                $started->close();
                $finished->close();
            }
        });
    }

    public function testSilentHeadersUseTheIdleTimeoutWhenTheTotalTimeoutIsDisabled(): void
    {
        $this->withStreamingServer('silent-headers', function (string $address): void {
            try {
                $this->expectException(ConnectionException::class);
                $this->expectExceptionMessage('The streaming request timed out before response headers.');

                (new Factory)->timeout(0)->withOptions(['stream' => true, 'read_timeout' => 0.2])->get('http://' . $address);
            } finally {
                $this->releaseServer($address);
            }
        });
    }

    public function testHeaderProgressResetsTheIdleTimeoutBeforeAHeaderLineIsComplete(): void
    {
        $this->withStreamingServer('trickle-headers', function (string $address): void {
            $response = (new Factory)->timeout(0)->withOptions(['stream' => true, 'read_timeout' => 0.4])->get('http://' . $address);

            try {
                $this->assertSame(200, $response->status());
                $this->assertSame('aaaaaaaa', $response->header('X-Partial'));
            } finally {
                $response->close();
            }
        });
    }

    public function testHeaderProgressDoesNotResetTheTotalHeaderDeadline(): void
    {
        $this->withStreamingServer('trickle-headers', function (string $address): void {
            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessage('The streaming request timed out before response headers.');

            (new Factory)->timeout(0.4)->withOptions(['stream' => true, 'read_timeout' => 0.4])->get('http://' . $address);
        });
    }

    #[DataProvider('idleTimeoutTransports')]
    public function testIdleStreamingReadTimeoutRaisesTheStreamReadError(bool $native): void
    {
        if (! $native && SWOOLE_VERSION_ID <= 60203) {
            $this->markTestSkipped('Swoole 6.2.3 and earlier lack the read-timeout fix: https://github.com/swoole/swoole-src/pull/6236.');
        }

        $this->withStreamingServer('delayed', function (string $address) use ($native): void {
            $finished = new Channel(1);
            try {
                $results = parallel([
                    'reader' => function () use ($address, $finished, $native): ?RuntimeException {
                        $response = (new Factory)->withOptions(['stream' => true, 'read_timeout' => 1])
                            ->setHandler($native ? new CurlStreamingHandler : new StreamHandler)
                            ->get('http://' . $address);
                        try {
                            $response->lines()->current();

                            return null;
                        } catch (RuntimeException $exception) {
                            return $exception;
                        } finally {
                            $finished->push(true);
                            $response->close();
                        }
                    },
                    'release' => function () use ($address, $finished): void {
                        $finished->pop(3);
                        $this->releaseServer($address);
                    },
                ]);

                $this->assertInstanceOf(RuntimeException::class, $results['reader']);
                $this->assertSame($native ? 'The streaming response read timed out.' : 'Unable to read from stream', $results['reader']->getMessage());
            } finally {
                $finished->close();
            }
        });
    }

    /**
     * Provide both transports for their respective read-timeout contracts.
     */
    public static function idleTimeoutTransports(): array
    {
        return [[true], [false]];
    }

    /**
     * Run a client against an independently controlled loopback server.
     */
    protected function withStreamingServer(string $mode, Closure $callback, int $hookFlags = SWOOLE_HOOK_ALL): void
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/Fixtures/streaming-server.php', $mode]);
        $process->setTimeout(10);
        $process->start();

        try {
            $deadline = microtime(true) + 5;

            while (! str_contains($process->getOutput(), "\n") && $process->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }

            $this->assertStringContainsString("\n", $process->getOutput(), $process->getErrorOutput());
            $address = trim($process->getOutput());

            $failure = null;
            run(function () use ($callback, $address, &$failure): void {
                try {
                    $callback($address);
                } catch (Throwable $exception) {
                    $failure = $exception;
                }
            }, $hookFlags);

            if ($failure !== null) {
                throw $failure;
            }

            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
        } finally {
            $process->stop(0);
        }
    }

    /**
     * Allow the server to send its final record.
     */
    protected function releaseServer(string $address): void
    {
        $connection = stream_socket_client('tcp://' . $address, $error, $message, 2);
        if ($connection === false) {
            throw new RuntimeException($message, $error);
        }

        fclose($connection);
    }
}
