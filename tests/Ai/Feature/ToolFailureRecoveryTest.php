<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Exception;
use Hypervel\Ai\Events\InvokingTool;
use Hypervel\Ai\Events\ToolFailed;
use Hypervel\Ai\Exceptions\ToolExecutionException;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\ToolResult;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Ai\Fixtures\Agents\ThrowingToolAgent;
use Hypervel\Tests\Ai\TestCase;
use RuntimeException;

class ToolFailureRecoveryTest extends TestCase
{
    #[WithConfig('app.debug', true)]
    public function testAThrowingToolReturnsAFailedResultToTheModel(): void
    {
        Exceptions::fake();

        ThrowingToolAgent::fake([
            new ToolCall('call_1', 'FixedNumberGenerator', []),
            'The tool could not run.',
        ]);

        $response = (new ThrowingToolAgent)->prompt('Generate');

        $this->assertSame('The tool could not run.', $response->text);
        $this->assertCount(1, $response->toolResults);
        $this->assertFalse($response->toolResults[0]->successful());
        $this->assertSame('The tool call failed: Forced to throw exception.', $response->toolResults[0]->error());

        Exceptions::assertReported(fn (Exception $exception): bool => $exception->getMessage() === 'Forced to throw exception.');
    }

    #[WithConfig('app.debug', false)]
    public function testAThrowingToolHidesTheExceptionMessageFromTheModelOutsideDebugMode(): void
    {
        Exceptions::fake();

        ThrowingToolAgent::fake([
            new ToolCall('call_1', 'FixedNumberGenerator', []),
            'The tool could not run.',
        ]);

        $response = (new ThrowingToolAgent)->prompt('Generate');

        $this->assertFalse($response->toolResults[0]->successful());
        $this->assertSame('The tool call failed.', $response->toolResults[0]->error());

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (Exception $exception): bool => $exception->getMessage() === 'Forced to throw exception.');
    }

    #[WithConfig('app.debug', true)]
    public function testAThrowingToolStreamsAFailedResult(): void
    {
        Exceptions::fake();

        ThrowingToolAgent::fake([
            new ToolCall('call_1', 'FixedNumberGenerator', []),
            'The tool could not run.',
        ]);

        $events = iterator_to_array((new ThrowingToolAgent)->stream('Generate'), false);

        $toolResults = array_values(array_filter($events, fn ($event): bool => $event instanceof ToolResult));

        $this->assertCount(1, $toolResults);
        $this->assertFalse($toolResults[0]->successful);
        $this->assertSame('The tool call failed: Forced to throw exception.', $toolResults[0]->error);
        $this->assertSame('The tool could not run.', TextDelta::combine(array_filter($events, fn ($event): bool => $event instanceof TextDelta)));

        Exceptions::assertReported(fn (Exception $exception): bool => $exception->getMessage() === 'Forced to throw exception.');
    }

    public function testAnExceptionFromAToolEventListenerStillFailsTheRun(): void
    {
        Exceptions::fake();

        Event::listen(InvokingTool::class, fn () => throw new RuntimeException('Listener failed.'));

        ThrowingToolAgent::fake([
            new ToolCall('call_1', 'FixedNumberGenerator', []),
            'Never reached.',
        ]);

        $this->assertThrows(fn (): mixed => (new ThrowingToolAgent)->prompt('Generate'), RuntimeException::class, 'Listener failed.');

        Exceptions::assertNothingReported();
    }

    public function testAToolFailedListenerThatRethrowsFailsTheRunWithTheToolException(): void
    {
        Exceptions::fake();

        Event::listen(ToolFailed::class, fn (ToolFailed $event) => throw $event->exception);

        ThrowingToolAgent::fake([
            new ToolCall('call_1', 'FixedNumberGenerator', []),
            'Never reached.',
        ]);

        $this->assertThrows(
            fn (): mixed => (new ThrowingToolAgent)->prompt('Generate'),
            fn (Exception $exception): bool => ! $exception instanceof ToolExecutionException
                && $exception->getMessage() === 'Forced to throw exception.',
        );

        Exceptions::assertNothingReported();
    }
}
