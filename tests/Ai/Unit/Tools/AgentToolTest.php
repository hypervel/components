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
use Hypervel\Tests\Ai\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;

class AgentToolTest extends TestCase
{
    use VerifiesDoubles;

    public function testAnAgentToolStreamsItsEventsByDefaultAndReturnsItsFinalText(): void
    {
        $stream = new StreamableAgentResponse('invocation-sub', fn (): Generator => yield from [
            new TextDelta('event-1', 'message-1', 'sub answer', time()),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ], new Meta('fake', 'model'));

        $agent = Double::for(Agent::class)->strict();
        $agent->expects('stream')->with('Do the thing')->returns($stream);
        $agent->expects('prompt')->never();

        $generator = (new AgentTool($agent))->stream(new Request(['task' => 'Do the thing']));

        $events = iterator_to_array($generator);

        $this->assertCount(2, $events);
        $this->assertInstanceOf(TextDelta::class, $events[0]);
        $this->assertSame('sub answer', $generator->getReturn());
    }

    public function testAStructuredSubAgentAnswersThroughARegularPromptOnTheStreamingPath(): void
    {
        $agent = Double::for(Agent::class, HasStructuredOutput::class)->strict();
        $agent->expects('stream')->never();
        $agent->expects('prompt')->returns(new StructuredAgentResponse(
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

    public function testAFailingSubAgentThrowsItsErrorOnTheStreamingPath(): void
    {
        $exception = new RuntimeException('provider exploded');

        $agent = Double::for(Agent::class)->strict();
        $agent->expects('stream')->throws($exception);

        $generator = (new AgentTool($agent))->stream(new Request(['task' => 'Do the thing']));

        try {
            iterator_to_array($generator);
            $this->fail('The sub-agent exception was not thrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
    }
}
