<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Closure;
use Hypervel\Http\Client\Factory;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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

    public function testBufferedFirstRecordArrivesBeforeTheNextServerWrite(): void
    {
        if (SWOOLE_VERSION_ID <= 60202) {
            $this->markTestSkipped('Buffered stream reads require https://github.com/swoole/swoole-src/pull/6235.');
        }

        $this->withStreamingServer('buffered', function (string $address): void {
            $received = new Channel(1);
            try {
                $results = parallel([
                    'reader' => function () use ($address, $received): array {
                        $response = (new Factory)->withOptions(['stream' => true, 'read_timeout' => 3])->get('http://' . $address);
                        try {
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

    public function testIdleStreamingReadTimeoutRaisesTheStreamReadError(): void
    {
        if (SWOOLE_VERSION_ID <= 60202) {
            $this->markTestSkipped('Stream read timeout errors require https://github.com/swoole/swoole-src/pull/6236.');
        }

        $this->withStreamingServer('delayed', function (string $address): void {
            $finished = new Channel(1);
            try {
                $results = parallel([
                    'reader' => function () use ($address, $finished): ?RuntimeException {
                        $response = (new Factory)->withOptions(['stream' => true, 'read_timeout' => 1])->get('http://' . $address);
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
                $this->assertSame('Unable to read from stream', $results['reader']->getMessage());
            } finally {
                $finished->close();
            }
        });
    }

    /**
     * Run a hooked client against an independently controlled loopback server.
     */
    protected function withStreamingServer(string $mode, Closure $callback): void
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/Fixtures/streaming-server.php', $mode]);
        $process->setTimeout(10);
        $process->start();

        try {
            $ready = $process->waitUntil(fn () => str_contains($process->getOutput(), "\n"));
            $this->assertTrue($ready, $process->getErrorOutput());
            $address = trim($process->getOutput());

            $failure = null;
            run(function () use ($callback, $address, &$failure): void {
                try {
                    $callback($address);
                } catch (Throwable $exception) {
                    $failure = $exception;
                }
            }, SWOOLE_HOOK_ALL);

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
