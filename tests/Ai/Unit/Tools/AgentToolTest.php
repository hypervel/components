<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Tools;

use Generator;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Tools\AgentTool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

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

    public function testAFailingSubAgentSurfacesItsErrorAsTheToolResultOnTheStreamingPath(): void
    {
        $agent = m::mock(Agent::class);
        $agent->shouldReceive('stream')->once()->andThrow(new RuntimeException('provider exploded'));

        $generator = (new AgentTool($agent))->stream(new Request(['task' => 'Do the thing']));

        $this->assertSame([], iterator_to_array($generator));
        $this->assertSame('Agent failed: provider exploded', $generator->getReturn());
    }

    #[DataProvider('deliveryModes')]
    public function testCancellationEscapesTheSubAgent(bool $streaming): void
    {
        $exception = new CanceledException('Sub-agent canceled.');
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
        } catch (CanceledException $caught) {
            $this->assertSame($exception, $caught);
        }
    }

    /**
     * Provide synchronous and streaming delegation.
     */
    public static function deliveryModes(): array
    {
        return ['prompt' => [false], 'stream' => [true]];
    }
}
