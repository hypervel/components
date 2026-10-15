<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses;

use Closure;
use Generator;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Responses\StreamedAgentResponse;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Hypervel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Throwable;

class StreamableAgentResponseTest extends TestCase
{
    public function testTopLevelTextAndUsageIgnoreTheOutputAStillRunningToolReported(): void
    {
        $response = new StreamableAgentResponse('invocation-1', fn (): Generator => yield from [
            new TextDelta('event-1', 'message-1', 'Hello', time()),
            new ToolResultEvent('event-2', new ToolResult('call-1', 'document_specialist', [], 'internal'), true, null, time(), preliminary: true),
            new ToolResultEvent('event-3', new ToolResult('call-1', 'document_specialist', [], 'internal monologue'), true, null, time(), preliminary: true),
            new TextDelta('event-4', 'message-1', ' world', time()),
            new StreamEnd('event-5', 'stop', new TextUsage(1, 2), time()),
        ], new Meta('fake', 'model'));

        iterator_to_array($response);

        $this->assertSame('Hello world', $response->text);
        $this->assertEquals(new TextUsage(1, 2), $response->usage);
    }

    public function testStreamedResponseToolAggregatesCountAToolCallOnceNotItsPreliminaryOutput(): void
    {
        $events = collect([
            new TextDelta('event-1', 'message-1', 'Answer', time()),
            new ToolCallEvent('event-2', new ToolCall('call-1', 'document_specialist', ['task' => 'Report']), time()),
            new ToolResultEvent('event-3', new ToolResult('call-1', 'document_specialist', [], 'partial'), true, null, time(), preliminary: true),
            new ToolResultEvent('event-4', new ToolResult('call-1', 'document_specialist', ['task' => 'Report'], 'done'), true, null, time()),
            new StreamEnd('event-5', 'stop', new TextUsage(1, 2), time()),
        ]);

        $response = new StreamedAgentResponse('invocation-1', $events, new Meta('fake', 'model'));

        $this->assertSame('Answer', $response->text);
        $this->assertSame(['call-1'], collect($response->toolCalls)->pluck('id')->all());
        $this->assertSame(['call-1'], collect($response->toolResults)->pluck('id')->all());
        $this->assertCount(0, $response->pendingApprovals);
    }

    public function testAFailureIsReportedToTheCatchCallbacksOnceHoweverOftenTheStreamIsReIterated(): void
    {
        $response = new StreamableAgentResponse('invocation-1', function (): Generator {
            yield new TextDelta('event-1', 'message-1', 'Hello', time());

            throw new RuntimeException('Boom.');
        }, new Meta('fake', 'model'));

        $failures = [];
        $iterationFailures = [];

        $response->onIterationFailure(function (?Throwable $exception) use (&$iterationFailures): void {
            $iterationFailures[] = $exception?->getMessage();
        });

        $response->catch(function (Throwable $exception) use (&$failures): void {
            $failures[] = $exception->getMessage();
        });

        for ($iteration = 0; $iteration < 2; ++$iteration) {
            try {
                iterator_to_array($response);
                $this->fail('The producer must fail on each attempt.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Boom.', $exception->getMessage());
            }
        }

        $this->assertSame(['Boom.'], $failures);
        $this->assertSame(['Boom.', 'Boom.'], $iterationFailures);
    }

    public function testCompletionFailureReachesTheIterationHandlerButNotPublicCatchCallbacks(): void
    {
        $response = new StreamableAgentResponse('invocation', fn (): array => []);
        $exception = new RuntimeException('Persistence failed.');
        $failure = null;
        $caught = false;
        $response->then(fn (): never => throw $exception);
        $response->catch(function () use (&$caught): void {
            $caught = true;
        });
        $response->onIterationFailure(function (?Throwable $exception) use (&$failure): void {
            $failure = $exception;
        });

        try {
            iterator_to_array($response);
            $this->fail('The completion callback must fail.');
        } catch (RuntimeException $caughtException) {
            $this->assertSame($exception, $caughtException);
        }

        $this->assertSame($exception, $failure);
        $this->assertFalse($caught);
    }

    public function testAbandonmentRunsTheIterationHandlerInTheCapturedContext(): void
    {
        $context = 'consumer';
        $seen = [];
        $response = new StreamableAgentResponse('invocation', function (): Generator {
            yield new TextDelta('event', 'message', 'Hello', 1);
        });
        $response->usingContext(function (Closure $callback) use (&$context): mixed {
            $previous = $context;
            $context = 'producer';

            try {
                return $callback();
            } finally {
                $context = $previous;
            }
        })->onIterationFailure(function (?Throwable $exception) use (&$context, &$seen): void {
            $seen[] = [$context, $exception];
        });

        foreach ($response as $event) {
            $this->assertSame('consumer', $context);
            break;
        }

        $this->assertSame([['producer', null]], $seen);
        $this->assertSame('consumer', $context);

        iterator_to_array($response);
        foreach ($response as $event) {
            break;
        }

        $this->assertCount(1, $seen);
    }

    public function testAResponseCanCompleteWithoutExplicitMetadata(): void
    {
        $response = new StreamableAgentResponse('invocation', fn (): array => [
            new TextDelta('event', 'message', 'Hello', 1),
        ]);

        iterator_to_array($response);

        $completed = null;
        $response->then(function (StreamedAgentResponse $result) use (&$completed): void {
            $completed = $result;
        });

        $this->assertInstanceOf(StreamedAgentResponse::class, $completed);
        $this->assertSame('Hello', $completed->text);
        $this->assertEquals(new Meta, $completed->meta);
    }

    public function testACompletedEmptyResponseDoesNotRunTheProducerOrCallbacksAgain(): void
    {
        $productions = 0;
        $completions = 0;
        $response = new StreamableAgentResponse('invocation', function () use (&$productions): array {
            ++$productions;

            return [];
        }, new Meta);
        $response->then(function () use (&$completions): void {
            ++$completions;
        });

        $this->assertSame([], iterator_to_array($response));
        $this->assertSame([], iterator_to_array($response));
        $this->assertSame(1, $productions);
        $this->assertSame(1, $completions);
    }
}
