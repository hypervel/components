<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai;

use Generator;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine\CanceledException;
use Throwable;

class StreamCancellationTest extends TestCase
{
    #[DataProvider('protocols')]
    public function testCancellationEscapesWithoutAnErrorOrCompletionFrame(?string $protocol): void
    {
        Exceptions::fake();
        $cancellation = new CanceledException('The client disconnected.');
        $cleanedUp = false;
        $completed = false;
        $caught = null;
        $stream = (new StreamableAgentResponse('invocation-1', function () use ($cancellation, &$cleanedUp): Generator {
            try {
                yield new StreamStart('message-1', 'openai', 'gpt-5', time());

                throw $cancellation;
            } finally {
                $cleanedUp = true;
            }
        }))->catch(function (Throwable $exception) use (&$caught): void {
            $caught = $exception;
        })->then(function () use (&$completed): void {
            $completed = true;
        });

        if ($protocol !== null) {
            $stream->{$protocol}();
        }

        $response = $stream->toResponse(request());
        $this->assertInstanceOf(IterableStreamedResponse::class, $response);
        $this->assertTrue($response->shouldCancelOnDisconnect());
        $chunks = [];
        $thrown = null;

        try {
            $response->streamTo(function (string $chunk) use (&$chunks): bool {
                $chunks[] = $chunk;

                return true;
            });
        } catch (CanceledException $exception) {
            $thrown = $exception;
        }

        $this->assertSame($cancellation, $thrown);
        $this->assertSame($cancellation, $caught);
        $this->assertTrue($cleanedUp);
        $this->assertFalse($completed);
        $this->assertNotEmpty($chunks);
        $this->assertDoesNotMatchRegularExpression('/error|finish|\[DONE\]/i', implode('', $chunks));
        Exceptions::assertNothingReported();
    }

    #[DataProvider('protocols')]
    public function testAFailedWriteDisposesTheProducerWithoutResumingIt(?string $protocol): void
    {
        $cleanedUp = false;
        $resumed = false;
        $completed = false;
        $stream = (new StreamableAgentResponse('invocation-1', function () use (&$cleanedUp, &$resumed): Generator {
            try {
                yield new StreamStart('message-1', 'openai', 'gpt-5', time());

                $resumed = true;

                yield new TextDelta('event-1', 'message-1', 'Hello', time());
            } finally {
                $cleanedUp = true;
            }
        }))->then(function () use (&$completed): void {
            $completed = true;
        });

        if ($protocol !== null) {
            $stream->{$protocol}();
        }

        $response = $stream->toResponse(request());
        $this->assertInstanceOf(IterableStreamedResponse::class, $response);
        $response->streamTo(fn (string $chunk): bool => false);

        $this->assertTrue($cleanedUp);
        $this->assertFalse($resumed);
        $this->assertFalse($completed);
    }

    /**
     * Provide the supported streaming response formats.
     */
    public static function protocols(): array
    {
        return [
            'SSE' => [null],
            'AG-UI' => ['usingAgentUserInteractionProtocol'],
            'Vercel' => ['usingVercelDataProtocol'],
        ];
    }
}
