<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class IterableStreamedResponseTest extends TestCase
{
    use VerifiesDoubles;

    public function testSendContentConsumesLazyChunksOnce(): void
    {
        $iterations = 0;
        $chunks = (function () use (&$iterations): iterable {
            ++$iterations;

            yield 'first';
            yield 'second';
        })();
        $response = new IterableStreamedResponse($chunks);
        unset($chunks);

        $this->assertSame(0, $iterations);
        $content = '';
        $level = ob_get_level();

        ob_start(static function (string $chunk) use (&$content): string {
            $content .= $chunk;

            return '';
        }, 1);

        try {
            $response->sendContent();
            $response->sendContent();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $this->assertSame('firstsecond', $content);
        $this->assertSame(1, $iterations);
        $this->assertFalse($response->streamTo(static fn (string $chunk): bool => true));
    }

    public function testStreamToStopsAndReleasesChunksWhenTheWriterFails(): void
    {
        $closed = false;
        $chunks = (function () use (&$closed): iterable {
            try {
                yield 'first';
                yield 'second';
            } finally {
                $closed = true;
            }
        })();
        $response = new IterableStreamedResponse($chunks);
        unset($chunks);
        $written = [];

        $handled = $response->streamTo(function (string $chunk) use (&$written): bool {
            $written[] = $chunk;

            return false;
        });

        $this->assertTrue($handled);
        $this->assertSame(['first'], $written);
        $this->assertTrue($closed);
        $this->assertFalse($response->streamTo(static fn (string $chunk): bool => true));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testStreamToPreservesCancellationWhenWritingAndReleasingChunksBothFail(bool $canceled): void
    {
        $writerFailure = new RuntimeException('writer failed');
        $releaseFailure = $canceled ? new CanceledException : new RuntimeException('release failed');
        $chunks = (function () use ($releaseFailure): iterable {
            try {
                yield 'first';
            } finally {
                throw $releaseFailure;
            }
        })();
        $response = new IterableStreamedResponse($chunks);
        unset($chunks);

        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($canceled ? $writerFailure : $releaseFailure));
        Container::getInstance()->instance(ExceptionHandler::class, $handler);

        try {
            $response->streamTo(static function (string $chunk) use ($writerFailure): bool {
                throw $writerFailure;
            });

            $this->fail('Expected the stream failure to propagate.');
        } catch (Throwable $exception) {
            $this->assertSame($canceled ? $releaseFailure : $writerFailure, $exception);
        }

        $this->assertFalse($response->streamTo(static fn (string $chunk): bool => true));
    }

    public function testSendContentPreservesOutputFailureWhenReleasingChunksAlsoFails(): void
    {
        $outputFailure = new RuntimeException('output failed');
        $releaseFailure = new RuntimeException('release failed');
        $chunks = (function () use ($releaseFailure): iterable {
            try {
                yield 'first';
            } finally {
                throw $releaseFailure;
            }
        })();
        $response = new IterableStreamedResponse($chunks);
        unset($chunks);

        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($releaseFailure));
        Container::getInstance()->instance(ExceptionHandler::class, $handler);

        $level = ob_get_level();
        ob_start();
        ob_start(static function (string $chunk) use ($outputFailure): never {
            throw $outputFailure;
        }, 1);

        try {
            try {
                $response->sendContent();

                $this->fail('Expected the output failure to be thrown.');
            } catch (RuntimeException $exception) {
                $this->assertSame($outputFailure, $exception);
            }
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $this->assertFalse($response->streamTo(static fn (string $chunk): bool => true));
    }

    public function testStreamToPropagatesReleaseFailureAfterWriterStopsNormally(): void
    {
        $releaseFailure = new RuntimeException('release failed');
        $chunks = (function () use ($releaseFailure): iterable {
            try {
                yield 'first';
            } finally {
                throw $releaseFailure;
            }
        })();
        $response = new IterableStreamedResponse($chunks);
        unset($chunks);

        try {
            $response->streamTo(static fn (string $chunk): bool => false);

            $this->fail('Expected the release failure to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($releaseFailure, $exception);
        }

        $this->assertFalse($response->streamTo(static fn (string $chunk): bool => true));
    }

    public function testSettingACallbackReleasesRetainedChunks(): void
    {
        $closed = false;
        $chunks = (function () use (&$closed): iterable {
            try {
                yield 'first';
            } finally {
                $closed = true;
            }
        })();
        $chunks->current();
        $response = new IterableStreamedResponse($chunks);
        unset($chunks);

        $response->setCallback(static function (): void {
            echo 'callback';
        });

        $this->assertTrue($closed);
        $this->assertFalse($response->streamTo(static fn (string $chunk): bool => true));

        ob_start();

        try {
            $response->sendContent();

            $this->assertSame('callback', ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }
}
