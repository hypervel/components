<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Tools;

use Generator;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasStructuredOutput;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Responses\StructuredAgentResponse;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Tools\AgentTool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Ai\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class AgentToolTest extends TestCase
{
    public function testAnAgentToolStreamsItsEventsByDefaultAndReturnsItsFinalText(): void
    {
        $stream = new StreamableAgentResponse('invocation-sub', fn (): Generator => yield from [
            new TextDelta('event-1', 'message-1', 'sub answer', time()),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ], new Meta('fake', 'model'));

        $agent = m::mock(Agent::class);
        $agent->shouldReceive('stream')->once()->with('Do the thing')->andReturn($stream);
        $agent->shouldNotReceive('prompt');

        $generator = (new AgentTool($agent))->stream(new Request(['task' => 'Do the thing']));

        $events = iterator_to_array($generator);

        $this->assertCount(2, $events);
        $this->assertInstanceOf(TextDelta::class, $events[0]);
        $this->assertSame('sub answer', $generator->getReturn());
    }

    public function testAStructuredSubAgentAnswersThroughARegularPromptOnTheStreamingPath(): void
    {
        $agent = m::mock(Agent::class, HasStructuredOutput::class);
        $agent->shouldNotReceive('stream');
        $agent->shouldReceive('prompt')->once()->andReturn(new StructuredAgentResponse(
            'invocation-sub',
            ['answer' => 42],
            '{"answer":42}',
            new TextUsage,
            new Meta('fake', 'model'),
        ));

        $generator = (new AgentTool($agent))->stream(new Request(['task' => 'Do the thing']));

        $this->assertSame([], iterator_to_array($generator));
        $this->assertSame('{"answer":42}', $generator->getReturn());
    }

    #[WithConfig('app.debug', true)]
    public function testAFailingSubAgentSurfacesItsErrorAsTheToolResultOnTheStreamingPath(): void
    {
        Exceptions::fake();
        $exception = new RuntimeException('provider exploded');

        $agent = m::mock(Agent::class);
        $agent->shouldReceive('stream')->once()->andThrow($exception);

        $generator = (new AgentTool($agent))->stream(new Request(['task' => 'Do the thing']));

        $this->assertSame([], iterator_to_array($generator));
        $this->assertSame('Agent failed: provider exploded', $generator->getReturn());

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);
    }

    #[DataProvider('promptedFailureModes')]
    #[WithConfig('app.debug', false)]
    public function testAFailingPromptedSubAgentIsReportedOnceAndHidesItsErrorOutsideDebugMode(bool $structuredStream): void
    {
        Exceptions::fake();
        $exception = new RuntimeException('provider exploded');

        $agent = $structuredStream ? m::mock(Agent::class, HasStructuredOutput::class) : m::mock(Agent::class);
        $agent->shouldNotReceive('stream');
        $agent->shouldReceive('prompt')->once()->andThrow($exception);

        $tool = new AgentTool($agent);
        $request = new Request(['task' => 'Do the thing']);

        if ($structuredStream) {
            $generator = $tool->stream($request);

            $this->assertSame([], iterator_to_array($generator));
            $this->assertSame('Agent failed.', $generator->getReturn());
        } else {
            $this->assertSame('Agent failed.', $tool->handle($request));
        }

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);
    }

    /**
     * Provide the prompt path and the structured sub-agent's streaming path, which prompts.
     */
    public static function promptedFailureModes(): array
    {
        return [
            'prompt' => [false],
            'structured stream' => [true],
        ];
    }

    #[DataProvider('deliveryModes')]
    public function testCancellationEscapesTheSubAgent(bool $streaming, bool $cleanupThrows): void
    {
        $exception = new CanceledException('Sub-agent canceled.');
        if ($cleanupThrows) {
            $exception = new RuntimeException('Sub-agent cleanup failed.', previous: $exception);
        }
        $agent = m::mock(Agent::class);

        if ($streaming) {
            $agent->shouldReceive('stream')->once()->andReturn(new StreamableAgentResponse('invocation', function () use ($exception): Generator {
                yield new TextDelta('event', 'message', 'Partial', time());

                throw $exception;
            }));
        } else {
            $agent->shouldReceive('prompt')->once()->andThrow($exception);
        }

        $tool = new AgentTool($agent);
        $request = new Request(['task' => 'Do the thing']);

        try {
            $streaming ? iterator_to_array($tool->stream($request)) : $tool->handle($request);
            $this->fail('Cancellation must reach the parent agent.');
        } catch (Throwable $caught) {
            $this->assertSame($exception, $caught);
        }
    }

    /**
     * Provide synchronous and streaming cancellation with normal and throwing cleanup.
     */
    public static function deliveryModes(): array
    {
        return [
            'prompt' => [false, false],
            'stream' => [true, false],
            'prompt cleanup failure' => [false, true],
            'stream cleanup failure' => [true, true],
        ];
    }
}
